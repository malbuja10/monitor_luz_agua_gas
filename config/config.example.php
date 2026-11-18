<?php
// Copiar a config/config.php y ajustar. config.php NO se sube al repositorio.
return [
    'db' => [
        'dsn'      => 'pgsql:host=127.0.0.1;port=5432;dbname=monitor',
        'usuario'  => 'monitor',
        'clave'    => 'CAMBIAR',
    ],
    'zona_horaria' => 'America/Bogota',
    'nombre_sitio' => 'Monitor Minkafab',
    // Ruta bajo la que Apache publica el sitio (Alias). '' si está en la raíz de un dominio.
    'ruta_base' => '/monitor',

    // Token compartido con los receptores Node (cabecera X-Ingest-Token).
    // Generar con: php -r 'echo bin2hex(random_bytes(24)), "\n";'
    'ingest_token' => 'CAMBIAR',
    // IPs autorizadas para /api/ingest.php (los receptores corren en el mismo servidor).
    'ingest_ips' => ['127.0.0.1', '::1'],

    // Guardar una lectura histórica como máximo cada N segundos por medidor,
    // salvo que cambie el acumulado, el estado de corte o las alarmas.
    // (los medidores de agua reportan cada ~10 s)
    'intervalo_historico_seg' => 300,

    // Minutos sin reportar para considerar un medidor "sin conexión".
    'minutos_offline' => 30,
];
