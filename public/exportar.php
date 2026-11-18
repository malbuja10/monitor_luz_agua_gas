<?php
// Exporta lecturas de un medidor a CSV (separador ';' para Excel en español).
//   ?id=1&desde=2026-11-01&hasta=2026-11-30   (por defecto últimos 30 días)
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
requerir('lector');

$id = (int) ($_GET['id'] ?? 0);
$m = q('SELECT numero, tipo FROM medidores WHERE id = ?', [$id])->fetch();
if (!$m) { http_response_code(404); exit('Medidor no encontrado.'); }

$fecha = fn($s, $def) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : $def;
$desde = $fecha($_GET['desde'] ?? null, date('Y-m-d', strtotime('-30 days')));
$hasta = $fecha($_GET['hasta'] ?? null, date('Y-m-d'));

header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename=\"lecturas_{$m['numero']}_{$desde}_{$hasta}.csv\"");
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM para Excel
fputcsv($out, ['fecha', 'acumulado_' . (UNIDADES[$m['tipo']] === 'kWh' ? 'kWh' : 'm3'), 'corte', 'bateria_v', 'rssi', 'datos'], ';');
$st = q("SELECT ts, valor, corte, bateria_v, rssi, datos FROM lecturas
         WHERE medidor_id = ? AND ts >= ?::date AND ts < ?::date + 1 ORDER BY ts", [$id, $desde, $hasta]);
while ($r = $st->fetch()) {
    fputcsv($out, [date('Y-m-d H:i:s', strtotime($r['ts'])), str_replace('.', ',', (string) $r['valor']),
                   $r['corte'], str_replace('.', ',', (string) $r['bateria_v']), $r['rssi'], $r['datos']], ';');
}
fclose($out);
