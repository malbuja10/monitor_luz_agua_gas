#!/usr/bin/env node
/**
 * zy-receiver.js — Receptor TCP de prueba para medidores Zhongyi (WiFi / 4G directo)
 * Basado en "ZHONGYI SMART ELECTRICITY METER COMMUNICATION PROTOCOL V2.0.0 (2026-02-24)".
 *
 * Uso:   node zy-receiver.js [puerto] [reenvío]
 *   node zy-receiver.js 10091                                  solo recibe
 *   node zy-receiver.js 10091 udp:58.215.245.18:20131          recibe y REPLICA por UDP hacia Zhongyi
 *   node zy-receiver.js 10091 host:puerto                      réplica por TCP
 * En UDP cada trama (login, reporte, latido) se envía como un datagrama aparte, desde un
 * socket UDP fijo por medidor; lo que Zhongyi responda a ese socket se entrega al medidor
 * (o queda en cola si el medidor está desconectado).
 * Variables opcionales:
 *   FORWARD_DOWNLINK=0   no dejar pasar al medidor los comandos que envíe Zhongyi (por defecto sí pasan)
 *   INGEST_TOKEN, INGEST_URL, INGEST_HOST   envío de reportes al sitio web (ver ingest-client.js).
 *     Cada reporte 0x12 se guarda en la base de datos y los comandos en cola en la web
 *     (relé, ciclo, lectura) se transmiten al medidor en ese momento.
 * Sin dependencias. Node 16+.
 *
 * 1) Registra en consola y en zy-raw.log TODO lo que llega, en hexadecimal (lo más importante
 *    en la primera prueba: así vemos el formato real que envía el firmware).
 * 2) Intenta decodificar las tramas conocidas (0x12 reporte, 0x07 estado, 0x08 alarma, 0xFF heartbeat...).
 * 3) Permite enviar comandos de bajada escribiendo en la consola (ver "help").
 *
 * Formato de subida según protocolo (conexión directa: WiFi, EG800G, M25, NB):
 *   [ID 8 bytes = IMEI+0] [CMD 1 byte] [ARG N bytes] [FID 1 byte]
 * Formato de bajada:  [CMD] [ARG] [FID]
 * Multibyte: little-endian. BCD invertido en energía, voltajes y corrientes.
 */
const net = require('net');
const fs = require('fs');
const readline = require('readline');
const { reportar, informarEnvios } = require('./ingest-client');

const PORT = parseInt(process.argv[2] || '10091', 10);
// Réplica: cada conexión de medidor abre su propia conexión hacia la plataforma Zhongyi y se
// reenvían los bytes tal cual (login ZY-IOT, reportes, latidos). Zhongyi lo ve como si el
// medidor estuviera conectado directamente.
const FWD = process.argv[3] ? (() => {
  const a = process.argv[3].split(':');
  const proto = (a[0] === 'udp' || a[0] === 'tcp') ? a.shift() : 'tcp';
  return { proto, host: a[0], port: +a[1] };
})() : null;
const dgram = require('dgram');
const FWD_DOWN = process.env.FORWARD_DOWNLINK !== '0';
const LOG = 'zy-raw.log';
const sockets = new Map(); // id -> socket
let lastId = null;

const hex = (b) => Buffer.from(b).toString('hex').toUpperCase();
const ts = () => new Date().toISOString();
const log = (line) => { console.log(line); fs.appendFileSync(LOG, line + '\n'); };

// ---- utilidades de decodificación ----
const bcdByte = (x) => ((x >> 4) * 10 + (x & 0x0f));
const bcdRev = (buf) => hex(Buffer.from(buf).reverse());            // "02 23" -> "2302"
const bcdNum = (buf, dec) => parseInt(bcdRev(buf), 10) / 10 ** dec;
const bcdTime = (b) => `20${hex([b[0]])}-${hex([b[1]])}-${hex([b[2]])} ${hex([b[3]])}:${hex([b[4]])}:${hex([b[5]])}`;
const valve = (s) => ({ 0: 'RELÉ CERRADO (con servicio)', 1: 'RELÉ ABIERTO (cortado)', 3: 'RELÉ ANORMAL' }[s & 0x03] ?? '?');
const ALERTS = { 0x01: 'tapa superior abierta', 0x02: 'tapa de bornes abierta', 0x04: 'corriente inversa',
  0x08: 'encendido', 0x10: 'falla de energía', 0x20: 'hora modificada', 0x40: 'bypass' };
const alertTxt = (a) => Object.entries(ALERTS).filter(([k]) => a & k).map(([, v]) => v).join(', ') || 'ninguna';

// Longitud de ARG por comando de subida (sin ID ni FID). null = variable/desconocida.
const ARGLEN = { 0x00: 1, 0x01: 1, 0x04: 2, 0x05: 2, 0x06: 1, 0x07: 1, 0x08: 7, 0x09: 6, 0x0A: 1, 0x1C: 4, 0xFF: 0 };

function decode(cmd, arg) {
  switch (cmd) {
    case 0x12: {
      const three = arg.length >= 33;
      let o = 0; const take = (n) => { const s = arg.subarray(o, o + n); o += n; return s; };
      const r = { tipo: three ? 'trifásico' : 'monofásico' };
      r.hora = bcdTime(take(6));
      r.energia_kWh = bcdNum(take(4), 2);
      r.saldo = take(4).readInt32LE(0) / 100;
      r.V = three ? [bcdNum(take(2), 1), bcdNum(take(2), 1), bcdNum(take(2), 1)] : [bcdNum(take(2), 1)];
      r.I = three ? [bcdNum(take(3), 3), bcdNum(take(3), 3), bcdNum(take(3), 3)] : [bcdNum(take(3), 3)];
      const st = take(1)[0], al = take(1)[0];
      r.estado = valve(st);
      r.alarma_rele = (al & 0x04) ? 'FALLA' : 'normal';
      if (o < arg.length) r.rssi_dBm = take(1).readInt8(0);
      if (o < arg.length) r.snr = take(1).readInt8(0);
      return r;
    }
    case 0x00: return { ACK_ERR: hex(arg) };
    case 0x01: return { ACK_OK_para_cmd: hex(arg) };
    case 0x04: return { ciclo_min: arg.readUInt16LE(0) };
    case 0x05: return { hora_fija: `${arg[0]}:${arg[1]}` };
    case 0x06: return { firmware: `${arg[0] >> 4}.${arg[0] & 0x0f}` };
    case 0x07: return { estado: valve(arg[0]) };
    case 0x08: return { hora: bcdTime(arg.subarray(0, 6)), alarmas: alertTxt(arg[6]) };
    case 0x09: return { hora: bcdTime(arg) };
    case 0x1C: return { saldo: arg.readInt32LE(0) / 100 };
    case 0xFF: return { heartbeat: true };
    default: return { sin_decodificar: hex(arg) };
  }
}

// Divide el buffer en tramas: ID(8) + CMD + ARG + FID. Para 0x12 se deduce la longitud (25/27/35/37 bytes de ARG+FID).
// CRC16/MODBUS (verificado con la trama "ZY-IOT" real del medidor 2026090100012)
function crc16modbus(b) {
  let c = 0xffff;
  for (const x of b) { c ^= x; for (let k = 0; k < 8; k++) c = (c & 1) ? (c >>> 1) ^ 0xa001 : c >>> 1; }
  return c;
}

function parseFrames(buf, onFrame, onRaw, onEvent) {
  let i = 0, prev = 0;
  const emit = () => { if (onRaw && i > prev) onRaw(buf.subarray(prev, i)); prev = i; };
  while (buf.length - i >= 4) {
    emit();
    // Trama de registro "ZY-IOT": 'ZY-IOT' + ver(1) + tipo(1) + len(2, BE) + payload ASCII + CRC16/MODBUS (BE)
    if (buf.subarray(i, i + 6).toString('latin1') === 'ZY-IOT') {
      if (buf.length - i < 12) break;
      const len = buf.readUInt16BE(i + 8), end = i + 10 + len + 2;
      if (buf.length < end) break;
      const body = buf.subarray(i, end - 2), crc = buf.readUInt16BE(end - 2);
      const id = buf.subarray(i + 10, i + 10 + len).toString('latin1');
      const tipo = buf[i + 7], crcOk = crc16modbus(body) === crc ? 'OK' : 'MAL';
      if (tipo === 1) {
        log(`         ZY-IOT REGISTRO (login) ID="${id}" CRC ${crcOk}`);
        // El ID de las tramas es el ID ASCII del login + '0' (p. ej. "860628083606787" -> 8606280836067870).
        if (crcOk === 'OK' && onEvent && /^[0-9A-F]{15}$/i.test(id)) onEvent('conexion', { id: (id + '0').toUpperCase() });
      }
      else if (tipo === 3 && len === 0 && buf.length - end >= 8) {
        // Visto en campo: ZY-IOT tipo 3 sin datos + ID de 8 bytes (latido / "listo para recibir")
        const id8 = hex(buf.subarray(end, end + 8));
        log(`         ZY-IOT LATIDO (tipo 3) ID=${id8} CRC ${crcOk}`);
        onFrame(id8, null, null, null);
        if (crcOk === 'OK' && onEvent) onEvent('conexion', { id: id8 });
        i = end + 8; continue;
      } else log(`         ZY-IOT tipo=${tipo} len=${len} datos=${hex(buf.subarray(i + 10, i + 10 + len))} CRC ${crcOk}`);
      i = end; continue;
    }
    // Reporte 0x12 según "connect board PROTOCOL" (Zhongyi):
    // ID(8) + 12 + tipo(01 mono/02 tri) + nº paquete + datos + checksum add8 + 01
    // datos: energía 4 | congelado 4 | saldo 4 | FP 2 | alarma 4 | estado 1 | batería 2 |
    //        tri: V 3x2, I 3x2, P 3x2  (mono: 10 bytes)  | [dirección 1 byte opcional]
    if (buf[i + 8] === 0x12 && (buf[i + 9] === 0x01 || buf[i + 9] === 0x02)) {
      const tri = buf[i + 9] === 0x02, base = 21 + (tri ? 18 : 10);
      let done = false;
      for (const dlen of [base, base + 1]) {
        const total = 11 + dlen + 2;
        if (buf.length - i < total) continue;
        const f = buf.subarray(i, i + total);
        const sum = f.subarray(0, total - 2).reduce((a, x) => (a + x) & 0xff, 0);
        if (sum !== f[total - 2] || f[total - 1] !== 0x01) continue;
        const d = f.subarray(11, 11 + dlen); let o = 0;
        const u = (n) => { const v = d.readUIntBE(o, n); o += n; return v; };
        const r = { tipo: tri ? 'trifásico' : 'monofásico', paquete: f[10] };
        r.energia_kWh = u(4) / 100;           // unidad 0,01 kWh (a confirmar con la pantalla)
        r.congelado_mes_ant_kWh = u(4) / 100;
        r.saldo = u(4) / 100;
        r.fp = u(2) / 100;
        r.alarma_hex = d.subarray(o, o + 4).toString('hex').toUpperCase(); o += 4;
        r.estado_hex = hex([d[o]]); o += 1;
        r.bateria_V = u(2) / 100;
        if (tri) {
          r.V = [u(2), u(2), u(2)].map((x) => x / 100);   // 0,01 V (verificado: 0x31F1 = 127,85 V)
          r.I = [u(2), u(2), u(2)].map((x) => x / 1000);  // 0,001 A (V*I = P verificado)
          r.P_W = [u(2), u(2), u(2)].map((x) => x / 1000); // 0,001 W ¿? (32866 = 32,87 W = V*I). OJO: 2 bytes => máx 65 W
        } else {
          r.datos_fase_hex = d.subarray(o, o + 10).toString('hex').toUpperCase(); o += 10;
        }
        if (o < dlen) r.direccion_bits = d[o].toString(2).padStart(8, '0');
        log(`         ID=${hex(f.subarray(0, 8))} REPORTE (checksum OK) -> ${JSON.stringify(r)}`);
        onFrame(hex(f.subarray(0, 8)), null, null, null);
        if (onEvent) onEvent('reporte', { id: hex(f.subarray(0, 8)), r, raw: hex(f) });
        i += total; done = true; break;
      }
      if (done) continue;
      if (buf.length - i < 11 + base + 3) break; // incompleta, esperar
    }
    // Respuesta a comando de bajada: 0A + identificador + código (00 OK, 01 ERR, 02 TIMEOUT) + 01 (con o sin ID delante)
    const off = [0, 8].find((k) => buf.length - i >= k + 4 && buf[i + k] === 0x0a && buf[i + k + 3] === 0x01
      && (k === 0 || buf.length - i === 12));
    if (off !== undefined) {
      const code = { 0: 'OK', 1: 'ERROR', 2: 'TIMEOUT' }[buf[i + off + 2]] ?? hex([buf[i + off + 2]]);
      const fn = { 1: 'relé', 2: 'ciclo', 3: 'token', 4: 'lectura' }[buf[i + off + 1]] ?? hex([buf[i + off + 1]]);
      log(`         RESPUESTA a comando ${fn}: ${code}`);
      if (onEvent) onEvent('respuesta', { id: off === 8 ? hex(buf.subarray(i, i + 8)) : null,
                                         fn: buf[i + off + 1], code: buf[i + off + 2] });
      i += off + 4; continue;
    }
    if (buf.length - i < 10) break;
    const id = buf.subarray(i, i + 8), cmd = buf[i + 8];
    let argLen = ARGLEN[cmd];
    if (cmd === 0x12) {
      const rest = buf.length - i - 9; // ARG + FID (+ tramas siguientes)
      argLen = [35, 33, 25, 23].find((n) => rest >= n + 1 && (rest === n + 1 || rest > 36)) ?? rest - 1;
    }
    if (argLen == null) argLen = buf.length - i - 10;   // desconocido: todo lo que queda
    if (buf.length - i < 10 + argLen) break;           // trama incompleta, esperar más datos
    const arg = buf.subarray(i + 9, i + 9 + argLen), fid = buf[i + 9 + argLen];
    onFrame(hex(id), cmd, arg, fid);
    i += 10 + argLen;
  }
  emit();
  return buf.subarray(i);
}

// ---- réplica UDP: un socket por medidor (ID de 8 bytes en hex) ----
const udpSocks = new Map();
function idOfRaw(raw, fallback) {
  if (raw.subarray(0, 6).toString('latin1') === 'ZY-IOT') {
    if (raw[7] === 1 && raw.length > 12) return (raw.subarray(10, raw.length - 2).toString('latin1') + '0').toUpperCase();
    if (raw.length >= 20) return hex(raw.subarray(raw.length - 8));
    return fallback;
  }
  return raw.length >= 10 && raw[0] !== 0x0a ? hex(raw.subarray(0, 8)) : fallback;
}
function udpFor(id) {
  if (udpSocks.has(id)) return udpSocks.get(id);
  const u = dgram.createSocket('udp4');
  u.on('message', (d, rinfo) => {
    log(`[${ts()}] RÉPLICA UDP RX <- ${rinfo.address}:${rinfo.port} para ${id} (${d.length} B): ${hex(d)}`);
    if (!FWD_DOWN) return log('         (no reenviado al medidor: FORWARD_DOWNLINK=0)');
    const s = sockets.get(id);
    if (s && !s.destroyed) { s.write(d); log(`         -> reenviado al medidor ${id}`); }
    else { queue.set(id, [...(queue.get(id) || []), hex(d)]); log(`         -> medidor desconectado, EN COLA para ${id}`); }
  });
  u.on('error', (e) => log(`[${ts()}] RÉPLICA UDP ERROR (${id}): ${e.message}`));
  udpSocks.set(id, u);
  return u;
}
function udpForward(raw, id) {
  if (!id) return log(`         (réplica UDP: trama sin ID conocido, no enviada: ${hex(raw)})`);
  udpFor(id).send(raw, FWD.port, FWD.host, (e) => {
    if (e) log(`[${ts()}] RÉPLICA UDP ERROR envío: ${e.message}`);
    else log(`[${ts()}] RÉPLICA UDP TX -> ${FWD.host}:${FWD.port} (${raw.length} B) ${id}`);
  });
}

// ---- servidor ----
const server = net.createServer((sock) => {
  const peer = `${sock.remoteAddress}:${sock.remotePort}`;
  let pending = Buffer.alloc(0);
  log(`[${ts()}] CONEXIÓN desde ${peer}`);
  // Sin keepalive TCP agresivo: el módulo 4G no responde sondeos tras ~60 s (se vio 'read ETIMEDOUT').
  sock.setTimeout(15 * 60 * 1000, () => { log(`[${ts()}] Inactiva 15 min, cerrando ${peer}`); sock.destroy(); });

  // ---- réplica hacia Zhongyi ----
  let up = null, upReady = false, upBuf = [];
  if (FWD && FWD.proto === 'tcp') {
    up = net.connect(FWD.port, FWD.host, () => {
      upReady = true;
      log(`[${ts()}] RÉPLICA conectada a ${FWD.host}:${FWD.port} para ${peer}`);
      upBuf.forEach((b) => up.write(b)); upBuf = [];
    });
    up.setTimeout(15 * 60 * 1000, () => up.destroy());
    up.on('data', (d) => {
      log(`[${ts()}] RÉPLICA RX <- Zhongyi (${d.length} B): ${hex(d)}${FWD_DOWN ? '  -> reenviado al medidor' : '  (no reenviado)'}`);
      if (FWD_DOWN && !sock.destroyed) sock.write(d);
    });
    up.on('error', (e) => log(`[${ts()}] RÉPLICA ERROR ${FWD.host}:${FWD.port}: ${e.message}`));
    up.on('close', () => { upReady = false; log(`[${ts()}] RÉPLICA cerrada para ${peer}`); });
  }
  const forward = (chunk) => {
    if (!up || up.destroyed) return;
    if (upReady) up.write(chunk); else upBuf.push(chunk);
  };

  sock.on('data', (chunk) => {
    forward(chunk);
    log(`[${ts()}] RX ${peer} (${chunk.length} B): ${hex(chunk)}`);
    const ascii = chunk.toString('latin1');
    if (/^[\x20-\x7E\r\n]+$/.test(ascii)) log(`         (texto ASCII: ${JSON.stringify(ascii)})`);
    pending = Buffer.concat([pending, chunk]);
    try {
      pending = parseFrames(pending, (id, cmd, arg, fid) => {
        if (!id) return;
        sockets.set(id, sock); lastId = id; sock.zyId = id;
        flushQueue(id, sock);
        if (cmd === null) return;
        log(`         ID=${id} CMD=0x${cmd.toString(16).padStart(2, '0').toUpperCase()} FID=${fid} -> ${JSON.stringify(decode(cmd, arg))}`);
      }, (raw) => {
        if (FWD && FWD.proto === 'udp') {
          const id = idOfRaw(raw, sock.zyId);
          if (id && !sock.zyId) sock.zyId = id;
          udpForward(raw, id);
        }
      }, (tipo, ev) => alSitio(tipo, ev, sock, peer));
    } catch (e) { log(`         (no se pudo decodificar: ${e.message})`); pending = Buffer.alloc(0); }
  });
  sock.on('close', () => {
    log(`[${ts()}] DESCONEXIÓN ${peer}${sock.zyId ? ' ID=' + sock.zyId : ''}`);
    if (up && !up.destroyed) up.end();
  });
  sock.on('error', (e) => log(`[${ts()}] ERROR ${peer}: ${e.message}`));
});
server.on('error', (e) => {
  if (e.code === 'EADDRINUSE') {
    console.error(`El puerto TCP ${PORT} ya está en uso: probablemente el receptor ya corre en pm2 ("pm2 ls").\n` +
      `Para ver lo que llega: pm2 logs zy-receiver. Para usarlo a mano, primero: pm2 stop zy-receiver`);
    process.exit(1);
  }
  throw e;
});
server.listen(PORT, '0.0.0.0', () => {
  log(`[${ts()}] Escuchando TCP en 0.0.0.0:${PORT}  (escribe "help")`);
  if (FWD) log(`[${ts()}] Réplica activa (${FWD.proto.toUpperCase()}) hacia ${FWD.host}:${FWD.port} (comandos de Zhongyi al medidor: ${FWD_DOWN ? 'SÍ' : 'NO'})`);
});

// ---- cola de comandos: si el medidor no está conectado, se envían en cuanto vuelva a reportar ----
const queue = new Map(); // id -> [hex, ...]
function sendFrame(id, sock, frame) {
  sock.write(Buffer.from(frame, 'hex'));
  log(`[${ts()}] TX -> ${id}: ${frame.toUpperCase()}`);
}
function flushQueue(id, sock) {
  const q = queue.get(id);
  if (!q || !q.length) return;
  queue.delete(id);
  q.forEach((f, k) => setTimeout(() => { if (!sock.destroyed) sendFrame(id, sock, f); }, 500 + k * 1500));
}

// ---- integración con el sitio web (public/api/ingest.php) ----
// Acciones genéricas de la web -> tramas "connect board PROTOCOL" (sin FID).
function tramaEnergia(c) {
  switch (c.accion) {
    case 'conectar':  return CMDS.on();
    case 'cortar':    return CMDS.off();
    case 'consultar': return CMDS.query();
    case 'ciclo': {
      const seg = parseInt(c.parametro, 10);
      return seg >= 10 && seg <= 86400 ? '0A02' + u32be(seg) + '01' : null;
    }
    default: return null;
  }
}
// Función de la respuesta 0A <fn> <código> 01 -> acciones que pudo haber originado.
const ACCIONES_FN = { 1: ['conectar', 'cortar'], 2: ['ciclo'], 4: ['consultar'] };

function aReporteEnergia({ id, r, raw }, peer) {
  const { energia_kWh, bateria_V, ...resto } = r;
  return {
    tipo: 'energia', dispositivo_id: id, desde: peer, evento: 'lectura', raw,
    valor: energia_kWh, bateria_v: bateria_V,
    // estado_hex y alarma_hex aún sin descifrar: van solo en 'datos' (no se interpretan como relé/alarmas).
    datos: { energia_kWh, bateria_V, ...resto },
  };
}

async function alSitio(tipo, ev, sock, peer) {
  try {
    if (tipo === 'respuesta') {
      const id = ev.id || sock.zyId;
      if (!id) return;
      await reportar({ tipo: 'energia', dispositivo_id: id, desde: peer, evento: 'ack',
        ack: { ok: ev.code === 0, acciones: ACCIONES_FN[ev.fn] || [],
               detalle: { 0: 'OK', 1: 'ERROR', 2: 'TIMEOUT' }[ev.code] ?? `código ${ev.code}` } });
      return;
    }
    if (tipo !== 'reporte' && tipo !== 'conexion') return;
    // Login y latidos actualizan la "última conexión" y permiten entregar comandos sin esperar al reporte.
    const comandos = await reportar(tipo === 'reporte' ? aReporteEnergia(ev, peer)
      : { tipo: 'energia', dispositivo_id: ev.id, desde: peer, evento: 'otro' });
    if (!comandos.length) return;
    const tramas = comandos.map((c) => ({ c, f: tramaEnergia(c) }));
    // Se registra la trama antes de transmitir, para que la respuesta encuentre su comando.
    await informarEnvios(tramas.map(({ c, f }) => (f ? { id: c.id, trama_hex: f }
      : { id: c.id, error: `acción ${c.accion} no soportada en energía` })));
    tramas.filter(({ f }) => f).forEach(({ f }, k) =>
      setTimeout(() => { if (!sock.destroyed) sendFrame(ev.id, sock, f); }, 500 + k * 1500));
  } catch (e) {
    log(`[${ts()}] ERROR integración web: ${e.message}`);
  }
}

// ---- comandos de bajada por consola ----
let fid = 1;
const nextFid = () => { const f = fid; fid = fid >= 0xFF ? 1 : fid + 1; return f.toString(16).padStart(2, '0'); };
// Bajada según "connect board PROTOCOL": 0A + identificador + datos + 01
const u32be = (n) => hex(Buffer.from([(n >>> 24) & 0xff, (n >> 16) & 0xff, (n >> 8) & 0xff, n & 0xff]));
const CMDS = {
  query:   () => '0A040001',                 // leer y reportar todo
  on:      () => '0A010101',                 // relé: 01 = On (con servicio)
  off:     () => '0A010001',                 // relé: 00 = Off (cortado)
  cycle:   (m) => '0A02' + u32be(Math.round(+m * 60)) + '01',  // ciclo en minutos -> segundos
};
const rl = readline.createInterface({ input: process.stdin });
rl.on('line', (line) => {
  const [c, a, b] = line.trim().split(/\s+/);
  if (!c) return;
  if (c === 'help') return console.log(`Comandos:
  list                      módulos conectados
  query                     [ID]   pedir reporte inmediato
  on | off                  [ID]   reconectar / cortar relé
  cycle <min>               [ID]   fijar ciclo de reporte
  raw <HEX>                 [ID]   enviar bytes tal cual (CMD+ARG+FID)
  (si no indicas ID se usa el último que reportó; si está desconectado, el comando queda en cola)`);
  if (c === 'list') return console.log([...sockets.entries()].map(([k, v]) => `${k} ${v.destroyed ? 'desconectado' : 'CONECTADO'}`),
    'en cola:', Object.fromEntries(queue));
  let frame, id;
  if (c === 'raw') { frame = a; id = b; }
  else if (c === 'cycle') { frame = CMDS.cycle(a); id = b; }
  else if (CMDS[c]) { frame = CMDS[c](); id = a; }
  else return console.log('Comando desconocido. Escribe "help".');
  id = id || lastId;
  const s = sockets.get(id);
  if (!id) return console.log('Indica el ID del medidor (ningún medidor ha reportado todavía).');
  if (!s || s.destroyed) {
    queue.set(id, [...(queue.get(id) || []), frame]);
    return log(`[${ts()}] EN COLA para ${id}: ${frame.toUpperCase()} (se enviará cuando vuelva a reportar)`);
  }
  sendFrame(id, s, frame);
});
