<?php
// Series para los gráficos de un medidor.
//   GET ?id=1&rango=24h|7d|30d|90d  -> {"serie": [[ms, valor], ...]}
//   GET ?id=1&diario=30             -> {"diario": [{"dia":"2026-11-01","consumo":1.234}, ...]}
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
requerir('lector', true);

$id = (int) ($_GET['id'] ?? 0);

if (isset($_GET['diario'])) {
    $dias = max(1, min(366, (int) $_GET['diario']));
    // Máximo acumulado por día; el consumo es la diferencia con el día anterior con datos.
    $filas = q("SELECT to_char(date_trunc('day', ts), 'YYYY-MM-DD') AS dia, min(valor) AS mn, max(valor) AS mx
                FROM lecturas WHERE medidor_id = ? AND valor IS NOT NULL
                  AND ts >= date_trunc('day', now()) - make_interval(days => ?::int)
                GROUP BY 1 ORDER BY 1", [$id, $dias])->fetchAll();
    $out = [];
    $prev = null;
    foreach ($filas as $f) {
        $consumo = $prev === null ? (float) $f['mx'] - (float) $f['mn'] : (float) $f['mx'] - $prev;
        $out[] = ['dia' => $f['dia'], 'consumo' => round(max(0, $consumo), 3)];
        $prev = (float) $f['mx'];
    }
    json_out(['diario' => $out]);
}

$rangos = ['24h' => ['1 day', null], '7d' => ['7 days', 'hour'], '30d' => ['30 days', 'hour'], '90d' => ['90 days', 'hour']];
[$intervalo, $agrupar] = $rangos[$_GET['rango'] ?? '24h'] ?? $rangos['24h'];

if ($agrupar) {
    $filas = q("SELECT extract(epoch FROM date_trunc('$agrupar', ts)) * 1000 AS t, max(valor) AS v
                FROM lecturas WHERE medidor_id = ? AND valor IS NOT NULL AND ts >= now() - ?::interval
                GROUP BY 1 ORDER BY 1", [$id, $intervalo])->fetchAll(PDO::FETCH_NUM);
} else {
    $filas = q('SELECT extract(epoch FROM ts) * 1000 AS t, valor AS v
                FROM lecturas WHERE medidor_id = ? AND valor IS NOT NULL AND ts >= now() - ?::interval
                ORDER BY ts', [$id, $intervalo])->fetchAll(PDO::FETCH_NUM);
}
json_out(['serie' => array_map(fn($f) => [(int) $f[0], (float) $f[1]], $filas)]);
