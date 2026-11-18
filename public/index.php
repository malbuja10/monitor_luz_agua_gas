<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
requerir('lector');

$tipo = $_GET['tipo'] ?? '';
if (!isset(TIPOS[$tipo])) $tipo = '';

$medidores = q("SELECT m.*, (SELECT json_agg(codigo) FROM alarmas a WHERE a.medidor_id = m.id AND a.fin IS NULL) AS alarmas_abiertas
                FROM medidores m WHERE m.activo AND (? = '' OR m.tipo = ?)
                ORDER BY m.tipo, m.nombre, m.numero", [$tipo, $tipo])->fetchAll();

$todos = q('SELECT tipo, ultima_conexion FROM medidores WHERE activo')->fetchAll();
$resumen = ['total' => count($todos), 'linea' => 0];
foreach (TIPOS as $t => $_) $resumen[$t] = 0;
foreach ($todos as $m) {
    $resumen[$m['tipo']]++;
    if (en_linea($m['ultima_conexion'])) $resumen['linea']++;
}
$nAlarmas = (int) q('SELECT count(*) FROM alarmas a JOIN medidores m ON m.id = a.medidor_id WHERE a.fin IS NULL AND m.activo')->fetchColumn();

cabecera('Panel', 'index.php');
?>
<div class="resumen">
  <div class="panel"><div class="cifra"><?= $resumen['total'] ?></div><div class="etq">Medidores activos</div></div>
  <div class="panel"><div class="cifra"><?= $resumen['linea'] ?></div><div class="etq">En línea (últimos <?= (int) config('minutos_offline', 30) ?> min)</div></div>
  <div class="panel"><div class="cifra"><?= $resumen['energia'] ?> · <?= $resumen['agua'] ?> · <?= $resumen['gas'] ?></div><div class="etq">Energía · Agua · Gas</div></div>
  <a class="panel" href="alarmas.php"><div class="cifra" style="color:<?= $nAlarmas ? 'var(--mal)' : 'inherit' ?>"><?= $nAlarmas ?></div><div class="etq">Alarmas activas</div></a>
</div>

<div class="filtros">
  <a href="index.php" class="<?= $tipo === '' ? 'activo' : '' ?>">Todos</a>
  <?php foreach (TIPOS as $t => $n): ?>
    <a href="?tipo=<?= $t ?>" class="<?= $tipo === $t ? 'activo' : '' ?>"><?= $n ?></a>
  <?php endforeach; ?>
  <span class="quien" id="actualizado" style="margin-left:auto">Se actualiza cada 30 s</span>
</div>

<?php if (!$medidores): ?>
  <div class="panel vacio">No hay medidores<?= $tipo ? ' de ' . h(strtolower(TIPOS[$tipo])) : '' ?>.
    <?php if (puede('admin')): ?><a href="medidor_editar.php">Agregar uno</a>.<?php endif; ?></div>
<?php endif; ?>

<div class="rejilla">
<?php foreach ($medidores as $m):
    $d = json_decode($m['ultimo_dato'] ?? '{}', true) ?: [];
    $alarmas = json_decode($m['alarmas_abiertas'] ?? '[]', true) ?: [];
?>
  <a class="panel tarjeta <?= h($m['tipo']) ?>" href="medidor.php?id=<?= (int) $m['id'] ?>">
    <div class="cab">
      <div>
        <div class="nombre"><?= h($m['nombre'] ?: $m['numero']) ?></div>
        <div class="num"><?= h(TIPOS[$m['tipo']]) ?> · <?= h($m['numero']) ?></div>
      </div>
      <?= chip_estado($m['ultima_conexion']) ?>
    </div>
    <div class="lectura"><?= fmt_num($d['valor'] ?? null, $m['tipo'] === 'energia' ? 2 : 3) ?>
      <small><?= UNIDADES[$m['tipo']] ?></small></div>
    <div class="chips">
      <?= chip_corte($d['corte'] ?? null) ?>
      <?php foreach ($alarmas as $a): ?><span class="chip mal"><?= h(nombre_alarma($a)) ?></span><?php endforeach; ?>
    </div>
    <div class="meta">
      <span>⟳ <?= h(hace($m['ultima_conexion'])) ?></span>
      <?php if (isset($d['bateria_v'])): ?><span>🔋 <?= fmt_num($d['bateria_v'], 2) ?> V</span><?php endif; ?>
      <?php if (isset($d['rssi'])): ?><span>📶 <?= (int) $d['rssi'] ?></span><?php endif; ?>
      <?php if ($m['ubicacion']): ?><span>📍 <?= h($m['ubicacion']) ?></span><?php endif; ?>
    </div>
  </a>
<?php endforeach; ?>
</div>
<script>setTimeout(() => location.reload(), 30000);</script>
<?php pie();
