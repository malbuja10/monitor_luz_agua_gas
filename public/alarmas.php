<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$u = requerir('lector');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requerir('operador');
    verificar_csrf();
    $n = q('UPDATE alarmas SET reconocida_por = ?, reconocida_en = now() WHERE id = ? AND reconocida_por IS NULL',
           [$u['id'], (int) ($_POST['reconocer'] ?? 0)])->rowCount();
    if ($n) auditar('alarma_reconocer', 'alarma ' . (int) $_POST['reconocer']);
    redirigir('alarmas.php');
}

$activas = q('SELECT a.*, m.nombre, m.numero, m.tipo, u.usuario AS reconoce
              FROM alarmas a JOIN medidores m ON m.id = a.medidor_id LEFT JOIN usuarios u ON u.id = a.reconocida_por
              WHERE a.fin IS NULL AND m.activo ORDER BY a.inicio DESC')->fetchAll();
$historial = q("SELECT a.*, m.nombre, m.numero, m.tipo FROM alarmas a JOIN medidores m ON m.id = a.medidor_id
                WHERE a.fin IS NOT NULL AND a.inicio >= now() - interval '30 days'
                ORDER BY a.inicio DESC LIMIT 200")->fetchAll();

cabecera('Alarmas', 'alarmas.php');
?>
<h1>Alarmas activas</h1>
<section class="panel">
<?php if (!$activas): ?><p class="vacio">No hay alarmas activas. ✔</p><?php else: ?>
  <div class="tabla-scroll"><table>
    <tr><th>Medidor</th><th>Alarma</th><th>Desde</th><th>Reconocida</th></tr>
    <?php foreach ($activas as $a): ?>
    <tr>
      <td><a href="medidor.php?id=<?= (int) $a['medidor_id'] ?>"><?= h($a['nombre'] ?: $a['numero']) ?></a>
          <span class="quien"><?= h(TIPOS[$a['tipo']]) ?></span></td>
      <td><span class="chip mal"><?= h(nombre_alarma($a['codigo'])) ?></span></td>
      <td><?= fmt_fecha($a['inicio']) ?> <span class="quien">(<?= h(hace($a['inicio'])) ?>)</span></td>
      <td><?php if ($a['reconocida_por']): ?><?= h($a['reconoce'] ?? '?') ?> · <?= fmt_fecha($a['reconocida_en']) ?>
          <?php elseif (puede('operador')): ?>
            <form method="post"><?= csrf_campo() ?><button name="reconocer" value="<?= (int) $a['id'] ?>" class="sec">Reconocer</button></form>
          <?php else: ?>—<?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
<?php endif; ?>
</section>

<h2>Historial (30 días)</h2>
<section class="panel">
<?php if (!$historial): ?><p class="vacio">Sin alarmas finalizadas en los últimos 30 días.</p><?php else: ?>
  <div class="tabla-scroll"><table>
    <tr><th>Medidor</th><th>Alarma</th><th>Inicio</th><th>Fin</th></tr>
    <?php foreach ($historial as $a): ?>
    <tr><td><a href="medidor.php?id=<?= (int) $a['medidor_id'] ?>"><?= h($a['nombre'] ?: $a['numero']) ?></a></td>
        <td><?= h(nombre_alarma($a['codigo'])) ?></td><td><?= fmt_fecha($a['inicio']) ?></td><td><?= fmt_fecha($a['fin']) ?></td></tr>
    <?php endforeach; ?>
  </table></div>
<?php endif; ?>
</section>
<?php pie();
