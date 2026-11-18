// Cliente para enviar reportes al sitio PHP (public/api/ingest.php). Sin dependencias, Node 18+.
// Variables de entorno:
//   INGEST_URL   (por defecto http://127.0.0.1/api/ingest.php)
//   INGEST_TOKEN (obligatorio; igual a 'ingest_token' en config/config.php)
//   INGEST_HOST  (opcional; cabecera Host si Apache usa VirtualHost por nombre, p. ej. dev.minkafab.com)
const URL_INGEST = process.env.INGEST_URL || 'http://127.0.0.1/api/ingest.php';
const TOKEN = process.env.INGEST_TOKEN || '';
const HOST = process.env.INGEST_HOST || '';

if (!TOKEN) console.warn('[ingest] INGEST_TOKEN no definido: los reportes NO se guardarán en la base de datos.');

// Se usa http.request (no fetch) porque fetch no permite fijar la cabecera Host.
function post(body) {
  if (!TOKEN) return Promise.resolve(null);
  const data = Buffer.from(JSON.stringify(body));
  const u = new URL(URL_INGEST);
  const lib = u.protocol === 'https:' ? require('https') : require('http');
  const headers = { 'Content-Type': 'application/json', 'Content-Length': data.length, 'X-Ingest-Token': TOKEN };
  if (HOST) headers.Host = HOST;
  return new Promise((resolve) => {
    const req = lib.request(u, { method: 'POST', headers, timeout: 5000 }, (res) => {
      const trozos = [];
      res.on('data', (c) => trozos.push(c));
      res.on('end', () => {
        let j = {};
        try { j = JSON.parse(Buffer.concat(trozos).toString()); } catch { /* respuesta no JSON */ }
        if (res.statusCode !== 200) { console.error(`[ingest] HTTP ${res.statusCode}: ${j.error || ''}`); return resolve(null); }
        resolve(j);
      });
    });
    req.on('timeout', () => req.destroy(new Error('timeout')));
    req.on('error', (e) => { console.error(`[ingest] ${e.message}`); resolve(null); });
    req.end(data);
  });
}

/** Envía un reporte normalizado. Devuelve la lista de comandos pendientes [{id, accion, parametro}]. */
async function reportar(reporte) {
  const r = await post({ reporte });
  return (r && r.comandos) || [];
}

/** Informa qué se transmitió: [{id, fid, trama_hex}] o [{id, error}]. */
async function informarEnvios(enviados) {
  if (enviados.length) await post({ enviados });
}

module.exports = { reportar, informarEnvios };
