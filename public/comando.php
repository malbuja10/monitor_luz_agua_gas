<?php
// Encola (o cancela) un comando de bajada. Requiere rol operador.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$u = requerir('operador');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirigir('index.php');
verificar_csrf();

$mid = (int) ($_POST['medidor_id'] ?? 0);
$m = q('SELECT id, tipo, numero, tiene_corte FROM medidores WHERE id = ?', [$mid])->fetch();
if (!$m) { flash('Medidor no encontrado.', 'error'); redirigir('index.php'); }
$volver = 'medidor.php?id=' . $mid;

if (isset($_POST['cancelar'])) {
    $n = q("UPDATE comandos SET estado = 'cancelado', detalle = ? WHERE id = ? AND medidor_id = ? AND estado = 'pendiente'",
           ['cancelado por ' . $u['usuario'], (int) $_POST['cancelar'], $mid])->rowCount();
    if ($n) auditar('comando_cancelar', "medidor {$m['numero']} comando " . (int) $_POST['cancelar']);
    flash($n ? 'Comando cancelado.' : 'El comando ya no estaba pendiente.', $n ? 'ok' : 'error');
    redirigir($volver);
}

$accion = (string) ($_POST['accion'] ?? '');
$param = null;
switch ($accion) {
    case 'conectar':
    case 'cortar':
        if (!$m['tiene_corte']) { flash('Este medidor no tiene válvula/relé controlable.', 'error'); redirigir($volver); }
        break;
    case 'consultar':
        break;
    case 'ciclo':
        $seg = (int) ($_POST['parametro'] ?? 0);
        if ($m['tipo'] !== 'energia' || $seg < 10 || $seg > 86400) {
            flash('Ciclo inválido (10–86400 s, solo energía).', 'error');
            redirigir($volver);
        }
        $param = (string) $seg;
        break;
    default:
        flash('Acción desconocida.', 'error');
        redirigir($volver);
}

// Un nuevo comando de corte reemplaza a uno opuesto todavía pendiente.
if (in_array($accion, ['conectar', 'cortar'], true)) {
    q("UPDATE comandos SET estado = 'cancelado', detalle = 'reemplazado por un comando posterior'
       WHERE medidor_id = ? AND estado = 'pendiente' AND accion IN ('conectar', 'cortar')", [$mid]);
}
q('INSERT INTO comandos (medidor_id, accion, parametro, creado_por) VALUES (?, ?, ?, ?)',
  [$mid, $accion, $param, $u['id']]);
auditar('comando', "medidor {$m['numero']}: $accion" . ($param !== null ? " $param" : ''));
flash(nombre_accion($accion, $m['tipo']) . ': en cola. Se enviará cuando el medidor reporte.');
redirigir($volver);
