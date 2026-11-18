<?php
declare(strict_types=1);

$cfgFile = __DIR__ . '/../config/config.php';
if (!is_file($cfgFile)) {
    http_response_code(500);
    exit('Falta config/config.php (copiar desde config/config.example.php).');
}
$CONFIG = require $cfgFile;
date_default_timezone_set($CONFIG['zona_horaria'] ?? 'America/Bogota');

function config(string $clave, $defecto = null)
{
    global $CONFIG;
    return $CONFIG[$clave] ?? $defecto;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $pdo = new PDO($c['dsn'], $c['usuario'], $c['clave'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("SET TIME ZONE " . $pdo->quote(config('zona_horaria', 'America/Bogota')));
    }
    return $pdo;
}

/** Ejecuta una consulta preparada y devuelve el statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function json_out($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function redirigir(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function ip_cliente(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function auditar(string $accion, string $detalle = ''): void
{
    q('INSERT INTO auditoria (usuario_id, accion, detalle, ip) VALUES (?, ?, ?, ?)',
      [usuario()['id'] ?? null, $accion, $detalle, ip_cliente()]);
}

// ---------- formato ----------
const TIPOS = ['energia' => 'Energía', 'agua' => 'Agua', 'gas' => 'Gas'];
const UNIDADES = ['energia' => 'kWh', 'agua' => 'm³', 'gas' => 'm³'];

function fmt_num($n, int $dec = 3): string
{
    return $n === null || $n === '' ? '—' : number_format((float) $n, $dec, ',', '.');
}

function fmt_fecha(?string $ts): string
{
    return $ts ? date('d/m/Y H:i:s', strtotime($ts)) : '—';
}

function hace(?string $ts): string
{
    if (!$ts) return 'nunca';
    $s = time() - strtotime($ts);
    if ($s < 60) return "hace {$s} s";
    if ($s < 3600) return 'hace ' . intdiv($s, 60) . ' min';
    if ($s < 86400) return 'hace ' . intdiv($s, 3600) . ' h';
    return 'hace ' . intdiv($s, 86400) . ' d';
}

function en_linea(?string $ts): bool
{
    return $ts && (time() - strtotime($ts)) < config('minutos_offline', 30) * 60;
}

const NOMBRES_ALARMA = [
    'bateria_baja'              => 'Batería baja',
    'flujo_inverso'             => 'Flujo inverso',
    'falla_valvula'             => 'Falla de válvula',
    'fuga'                      => 'Fuga',
    'falla_sensor_temperatura'  => 'Falla sensor de temperatura',
    'falla_sensor_flujo_o_ATC'  => 'Falla sensor de flujo / ATC',
    'instalado_al_reves'        => 'Instalado al revés',
    'falla_rele'                => 'Falla de relé',
    'tapa_abierta'              => 'Tapa abierta',
    'corriente_inversa'         => 'Corriente inversa',
];

function nombre_alarma(string $c): string
{
    return NOMBRES_ALARMA[$c] ?? $c;
}

const NOMBRES_CORTE = [
    'abierta' => 'Válvula abierta', 'cerrada' => 'Válvula cerrada',
    'con_servicio' => 'Con servicio', 'cortado' => 'Cortado',
    'anormal' => 'Anormal',
];

function nombre_corte(?string $c): string
{
    return $c === null ? '—' : (NOMBRES_CORTE[$c] ?? $c);
}

require __DIR__ . '/auth.php';
require __DIR__ . '/layout.php';
