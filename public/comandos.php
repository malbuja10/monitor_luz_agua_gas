<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
requerir('operador');

$estado = $_GET['estado'] ?? '';
$estados = ['pendiente', 'enviado', 'confirmado', 'error', 'cancelado'];
if (!in_array($estado, $estados, true)) $estado = '';

$cmds = q("SELECT c.*, m.nombre, m.numero, m.tipo, u.usuario FROM comandos c
           JOIN medidores m ON m.id = c.medidor_id LEFT JOIN usuarios u ON u.id = c.creado_por
           WHERE (? = '' OR c.estado = ?) ORDER BY c.id DESC LIMIT 300", [$estado, $estado])->fetchAll();

cabecera('Comandos', 'comandos.php');
?>
<h1>Comandos</h1>
<div class="filtros">
  <a href="comandos.php" class="<?= $estado === '' ? 'activo' : '' ?>">Todos</a>
  <?php foreach ($estados as $e): ?><a href="?estado=<?= $e ?>" class="<?= $estado === $e ? 'activo' : '' ?>"><?= ucfirst($e) ?></a><?php endforeach; ?>
</div>
<section class="panel">
<?php if (!$cmds): ?><p class="vacio">Sin comandos.</p><?php else: ?>
  <div class="tabla-scroll"><table>
    <tr><th>#</th><th>Creado</th><th>Medidor</th><th>Acción</th><th>Estado</th><th>Enviado</th><th>Confirmado</th><th>Trama</th><th>Usuario</th></tr>
    <?php foreach ($cmds as $c): ?>
    <tr>
      <td><?= (int) $c['id'] ?></td>
      <td><?= fmt_fecha($c['creado']) ?></td>
      <td><a href="medidor.php?id=<?= (int) $c['medidor_id'] ?>"><?= h($c['nombre'] ?: $c['numero']) ?></a></td>
      <td><?= h(nombre_accion($c['accion'], $c['tipo'])) ?><?= $c['parametro'] !== null ? ' (' . h($c['parametro']) . ')' : '' ?></td>
      <td><span class="chip <?= ['confirmado' => 'ok', 'error' => 'mal', 'pendiente' => 'aviso', 'enviado' => 'aviso'][$c['estado']] ?? 'apagado' ?>"
            title="<?= h($c['detalle'] ?? '') ?>"><?= h($c['estado']) ?></span></td>
      <td><?= fmt_fecha($c['enviado']) ?></td>
      <td><?= fmt_fecha($c['confirmado']) ?></td>
      <td><code><?= h($c['trama_hex'] ?? '') ?></code></td>
      <td><?= h($c['usuario'] ?? '') ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
<?php endif; ?>
</section>
<?php pie();
