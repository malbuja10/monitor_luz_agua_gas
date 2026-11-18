<?php
// Endpoint para los receptores Node (solo localhost + token).
//   POST {"reporte": {...}}              -> {"ok":true, "medidor_id":1, "comandos":[...]}
//   POST {"enviados": [{id,fid,trama_hex} | {id,error}]}  -> {"ok":true}
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/ingesta.php';

// Local: IP en la lista, o petición del propio servidor a su IP pública (p. ej. vía https://dominio/...).
$local = in_array(ip_cliente(), config('ingest_ips', []), true) || ip_cliente() === ($_SERVER['SERVER_ADDR'] ?? '-');
if (!$local) json_out(['error' => 'ip no autorizada'], 403);
$tok = $_SERVER['HTTP_X_INGEST_TOKEN'] ?? '';
if (config('ingest_token') === 'CAMBIAR' || !hash_equals((string) config('ingest_token'), $tok)) {
    json_out(['error' => 'token inválido'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'usar POST'], 405);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) json_out(['error' => 'JSON inválido'], 400);

try {
    $resp = ['ok' => true];
    if (is_array($in['reporte'] ?? null)) {
        $resp += procesar_reporte($in['reporte']);
    }
    foreach (($in['enviados'] ?? []) as $e) {
        if (is_array($e)) registrar_envio($e);
    }
    json_out($resp);
} catch (InvalidArgumentException $e) {
    json_out(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('ingest: ' . $e->getMessage());
    json_out(['error' => 'error interno'], 500);
}
