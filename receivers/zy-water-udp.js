#!/usr/bin/env node
// Receptor UDP para medidores de agua ultrasónicos ZYsmart (WiFi / 4G directo)
// Uso: node zy-water-udp.js [puerto=10091] [archivo_log=zy-water.jsonl]
// Protocolo (manual "Ultrasonic water meter Class Function"):
//   [ID 8 bytes = IMEI+'0'] [CMD 1] [ARG n] [FID 1]   -- little-endian
//
// Además de registrar en el .jsonl, envía cada trama al sitio web (ver ingest-client.js) y,
// cuando el medidor reporta, le transmite los comandos que haya en cola (válvula / consulta).
// Variables de entorno:
//   INGEST_TOKEN, INGEST_URL, INGEST_HOST   -> ver ingest-client.js
//   WATER_DOWNLINK_ID=1   antepone ID 8 B (IMEI+'0') a las tramas de bajada (pendiente confirmar
//                         con Zhongyi; por defecto 0 = formato del manual: CMD ARG FID)
const dgram = require('dgram');
const fs = require('fs');
const { reportar, informarEnvios } = require('./ingest-client');

const PORT = parseInt(process.argv[2] || '10091', 10);
const LOG = process.argv[3] || 'zy-water.jsonl';
const DOWNLINK_ID = process.env.WATER_DOWNLINK_ID === '1';

const u32 = (b, o) => b.readUInt32LE(o);
const u16 = (b, o) => b.readUInt16LE(o);
const meterNo = (b) => Buffer.from(b).reverse().toString('hex'); // 7 bytes LE -> "20260815000001"

const STATUS_VALVE = { 0: 'abierta', 1: 'cerrada', 3: 'anormal' };
const ALARMS = [
  [0, 'bateria_baja'], [1, 'flujo_inverso'], [2, 'falla_valvula'], [3, 'fuga'],
  [4, 'falla_sensor_temperatura'], [5, 'falla_sensor_flujo_o_ATC'], [6, 'instalado_al_reves'],
];

function decodeStatus(s) {
  return { valvula: STATUS_VALVE[s & 0x03] ?? 'desconocido', bateria_baja: !!(s & 0x04) };
}
function decodeAlarm(a) {
  return ALARMS.filter(([bit]) => a & (1 << bit)).map(([, n]) => n);
}

function decode(buf) {
  if (buf.length < 10) return { error: 'trama corta' };
  const id = buf.subarray(0, 8).toString('hex');           // IMEI + '0'
  const imei = id.slice(0, 15);
  const body = buf.subarray(8);
  const cmd = body[0];
  const fid = body[body.length - 1];
  const arg = body.subarray(1, body.length - 1);
  const out = { imei, cmd: cmd.toString(16).padStart(2, '0'), fid, activo: fid === 0 };

  switch (cmd) {
    case 0x02: // PAFDS: lectura + estado (ultrasónico: 21 bytes de argumento)
      if (arg.length >= 21) {
        Object.assign(out, {
          tipo: 'lectura',
          medidor: meterNo(arg.subarray(0, 7)),
          acumulado_m3: u32(arg, 7) / 1000,
          recarga_m3: u32(arg, 11) / 1000,
          estado: decodeStatus(arg[15]),
          alarmas: decodeAlarm(arg[16]),
          bateria_v: u16(arg, 17) / 100,
          rssi: arg.readInt8(19),
          snr: arg.readInt8(20),
        });
      }
      break;
    case 0x00: case 0x01:
      out.tipo = cmd ? 'ACK_OK' : 'ACK_ERROR';
      out.respuesta_a = arg[0]?.toString(16).padStart(2, '0');
      break;
    case 0x06: out.tipo = 'ciclo'; out.ciclo_raw = arg.toString('hex'); break;
    case 0x08: out.tipo = 'bateria'; out.raw = arg.toString('hex'); break;
    case 0x09: out.tipo = 'estado'; out.estado = decodeStatus(arg[0]); break;
    case 0x0a: out.tipo = 'alarma'; out.alarmas = decodeAlarm(arg[0]); break;
    case 0x0b: out.tipo = 'firmware'; out.raw = arg.toString('hex'); break;
    default: out.tipo = 'desconocido'; out.raw = arg.toString('hex');
  }
  return out;
}

/** Convierte la trama decodificada al reporte normalizado que espera api/ingest.php. */
function aReporte(d, hex, desde) {
  const r = { tipo: 'agua', dispositivo_id: d.imei, desde, raw: hex, evento: 'otro', datos: { ...d } };
  delete r.datos.imei;
  const alarmas = (est, al) => {
    const a = [...(al || [])];
    if (est?.bateria_baja && !a.includes('bateria_baja')) a.push('bateria_baja');
    return a;
  };
  if (d.tipo === 'lectura') {
    Object.assign(r, {
      evento: 'lectura', numero: d.medidor, valor: d.acumulado_m3, corte: d.estado.valvula,
      bateria_v: d.bateria_v, rssi: d.rssi, alarmas: alarmas(d.estado, d.alarmas),
    });
  } else if (d.tipo === 'ACK_OK' || d.tipo === 'ACK_ERROR') {
    r.evento = 'ack';
    r.ack = { ok: d.tipo === 'ACK_OK', cmd: d.respuesta_a, fid: d.fid };
  } else if (d.tipo === 'estado') {
    r.corte = d.estado.valvula;
  } else if (d.tipo === 'alarma') {
    r.alarmas = d.alarmas;
  }
  return r;
}

// ---- bajada ----
let fidSeq = 1;
const nextFid = () => { const f = fidSeq; fidSeq = fidSeq >= 0xff ? 1 : fidSeq + 1; return f; };

/** Arma la trama de bajada para un comando de la cola. Devuelve null si no aplica a agua. */
function tramaBajada(cmd, idHex) {
  const fid = nextFid();
  let cuerpo;
  switch (cmd.accion) {
    case 'conectar':  cuerpo = [0x04, 0x55]; break; // abrir válvula
    case 'cortar':    cuerpo = [0x04, 0x99]; break; // cerrar válvula
    case 'consultar': cuerpo = [0x02]; break;
    default: return null;
  }
  const partes = [Buffer.from([...cuerpo, fid])];
  if (DOWNLINK_ID) partes.unshift(Buffer.from(idHex, 'hex'));
  return { fid, buf: Buffer.concat(partes) };
}

if (require.main === module) {
  const sock = dgram.createSocket('udp4');
  sock.on('message', async (msg, rinfo) => {
    const desde = `${rinfo.address}:${rinfo.port}`;
    const hex = msg.toString('hex');
    const d = decode(msg);
    const rec = { ts: new Date().toISOString(), desde, hex, ...d };
    console.log(JSON.stringify(rec));
    fs.appendFile(LOG, JSON.stringify(rec) + '\n', () => {});
    if (d.error) return;

    const comandos = await reportar(aReporte(d, hex, desde));
    if (!comandos.length) return;
    // Primero se registra FID y trama (para que un ACK rápido encuentre su comando), luego se transmite.
    const tramas = comandos.map((c) => ({ c, t: tramaBajada(c, hex.slice(0, 16)) }));
    await informarEnvios(tramas.map(({ c, t }) => (t
      ? { id: c.id, fid: t.fid, trama_hex: t.buf.toString('hex').toUpperCase() }
      : { id: c.id, error: `acción ${c.accion} no soportada en agua` })));
    const errores = [];
    for (const { c, t } of tramas) {
      if (!t) continue;
      try {
        await new Promise((ok, mal) => sock.send(t.buf, rinfo.port, rinfo.address, (e) => (e ? mal(e) : ok())));
        const tx = { ts: new Date().toISOString(), hacia: desde, tx: t.buf.toString('hex'), comando: c };
        console.log(JSON.stringify(tx));
        fs.appendFile(LOG, JSON.stringify(tx) + '\n', () => {});
      } catch (e) {
        errores.push({ id: c.id, error: e.message });
      }
    }
    await informarEnvios(errores);
  });
  sock.on('error', (e) => {
    if (e.code === 'EADDRINUSE') {
      console.error(`El puerto UDP ${PORT} ya está en uso: probablemente el receptor ya corre en pm2 ("pm2 ls").\n` +
        `Para ver lo que llega: pm2 logs zy-water-udp. Para usarlo a mano, primero: pm2 stop zy-water-udp`);
      process.exit(1);
    }
    throw e;
  });
  sock.bind(PORT, () => console.log(`Escuchando UDP ${PORT} -> ${LOG}${DOWNLINK_ID ? ' (bajada con ID)' : ''}`));
}
module.exports = { decode, aReporte, tramaBajada };
