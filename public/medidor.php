<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
requerir('lector');

$id = (int) ($_GET['id'] ?? 0);
$m = q('SELECT * FROM medidores WHERE id = ?', [$id])->fetch();
if (!$m) { http_response_code(404); exit('Medidor no encontrado.'); }

$d = json_decode($m['ultimo_dato'] ?? '{}', true) ?: [];
$unidad = UNIDADES[$m['tipo']];
$dec = $m['tipo'] === 'energia' ? 2 : 3;
$alarmas = q('SELECT a.*, u.usuario AS reconoce FROM alarmas a LEFT JOIN usuarios u ON u.id = a.reconocida_por
              WHERE a.medidor_id = ? ORDER BY (a.fin IS NULL) DESC, a.inicio DESC LIMIT 20', [$id])->fetchAll();
$comandos = q('SELECT c.*, u.usuario FROM comandos c LEFT JOIN usuarios u ON u.id = c.creado_por
               WHERE c.medidor_id = ? ORDER BY c.id DESC LIMIT 15', [$id])->fetchAll();
$lecturas = q('SELECT * FROM lecturas WHERE medidor_id = ? ORDER BY ts DESC LIMIT 50', [$id])->fetchAll();

/** Aplana los datos decodificados para mostrarlos como lista. */
function filas_datos(array $datos, string $pre = ''): array
{
    $out = [];
    foreach ($datos as $k => $v) {
        if (in_array($k, ['hex', 'raw'], true)) continue;
        if (is_array($v) && array_is_list($v) && !array_filter($v, 'is_array')) {
            $out[$pre . $k] = implode(' / ', array_map(fn($x) => is_bool($x) ? ($x ? 'sí' : 'no') : (string) $x, $v));
        } elseif (is_array($v)) {
            $out += filas_datos($v, $pre . $k . '.');
        } else {
            $out[$pre . $k] = is_bool($v) ? ($v ? 'sí' : 'no') : (string) $v;
        }
    }
    return $out;
}

cabecera($m['nombre'] ?: $m['numero']);
?>
<div class="titulo-fila">
  <div>
    <h1><?= h($m['nombre'] ?: $m['numero']) ?></h1>
    <div class="quien"><?= h(TIPOS[$m['tipo']]) ?> · Nº <?= h($m['numero']) ?> · ID <code><?= h($m['dispositivo_id'] ?? '—') ?></code>
      <?= $m['comunicacion'] ? ' · ' . h($m['comunicacion']) : '' ?><?= $m['modelo'] ? ' · ' . h($m['modelo']) : '' ?>
      <?= $m['activo'] ? '' : ' · <strong>INACTIVO</strong>' ?></div>
  </div>
  <div class="chips">
    <?= chip_estado($m['ultima_conexion']) ?>
    <?php if (puede('admin')): ?><a class="btn sec" href="medidor_editar.php?id=<?= $id ?>">Editar</a><?php endif; ?>
  </div>
</div>

<div class="dos">
  <section class="panel">
    <h2>Última lectura</h2>
    <dl class="datos">
      <dt>Acumulado</dt><dd><strong><?= fmt_num($d['valor'] ?? null, $dec) ?> <?= $unidad ?></strong></dd>
      <?php if (isset($d['corte'])): ?><dt><?= $m['tipo'] === 'energia' ? 'Relé' : 'Válvula' ?></dt><dd><?= chip_corte($d['corte']) ?></dd><?php endif; ?>
      <?php if (isset($d['bateria_v'])): ?><dt>Batería</dt><dd><?= fmt_num($d['bateria_v'], 2) ?> V</dd><?php endif; ?>
      <?php if (isset($d['rssi'])): ?><dt>RSSI</dt><dd><?= (int) $d['rssi'] ?></dd><?php endif; ?>
      <dt>Último reporte</dt><dd><?= fmt_fecha($m['ultima_conexion']) ?> (<?= h(hace($m['ultima_conexion'])) ?>)</dd>
      <dt>Desde</dt><dd class="mono"><?= h($m['ultima_ip'] ?? '—') ?></dd>
      <?php foreach (filas_datos($d['datos'] ?? []) as $k => $v): ?>
        <dt><?= h($k) ?></dt><dd class="mono"><?= h($v) ?></dd>
      <?php endforeach; ?>
    </dl>
  </section>

  <section class="panel">
    <h2>Comandos</h2>
    <?php if (!puede('operador')): ?>
      <p class="vacio">Su rol no permite enviar comandos.</p>
    <?php else: ?>
      <p class="quien">Los comandos quedan en cola y se envían cuando el medidor vuelve a reportar.</p>
      <form method="post" action="comando.php" class="fila" style="margin-bottom:12px">
        <?= csrf_campo() ?><input type="hidden" name="medidor_id" value="<?= $id ?>">
        <?php if ($m['tiene_corte']): ?>
          <button name="accion" value="conectar" class="ok"
                  onclick="return confirm('¿<?= h(nombre_accion('conectar', $m['tipo'])) ?>?')"><?= h(nombre_accion('conectar', $m['tipo'])) ?></button>
          <button name="accion" value="cortar" class="peligro"
                  onclick="return confirm('¿<?= h(nombre_accion('cortar', $m['tipo'])) ?>?')"><?= h(nombre_accion('cortar', $m['tipo'])) ?></button>
        <?php endif; ?>
        <button name="accion" value="consultar" class="sec">Solicitar lectura</button>
      </form>
      <?php if ($m['tipo'] === 'energia'): ?>
      <form method="post" action="comando.php" class="fila">
        <?= csrf_campo() ?><input type="hidden" name="medidor_id" value="<?= $id ?>">
        <input type="hidden" name="accion" value="ciclo">
        <div><label for="ciclo">Ciclo de reporte (segundos)</label>
          <input id="ciclo" name="parametro" type="number" min="10" max="86400" value="300" required></div>
        <button class="sec">Cambiar ciclo</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>

    <h2 style="margin-top:20px">Historial de comandos</h2>
    <?php if (!$comandos): ?><p class="vacio">Sin comandos.</p><?php else: ?>
    <div class="tabla-scroll"><table>
      <tr><th>Fecha</th><th>Acción</th><th>Estado</th><th>Usuario</th><th></th></tr>
      <?php foreach ($comandos as $c): ?>
      <tr>
        <td><?= fmt_fecha($c['creado']) ?></td>
        <td><?= h(nombre_accion($c['accion'], $m['tipo'])) ?><?= $c['parametro'] !== null ? ' (' . h($c['parametro']) . ')' : '' ?>
          <?php if ($c['trama_hex']): ?><br><code title="trama enviada"><?= h($c['trama_hex']) ?></code><?php endif; ?></td>
        <td><span class="chip <?= ['confirmado' => 'ok', 'error' => 'mal', 'pendiente' => 'aviso', 'enviado' => 'aviso'][$c['estado']] ?? 'apagado' ?>"
              title="<?= h($c['detalle'] ?? '') ?>"><?= h($c['estado']) ?></span></td>
        <td><?= h($c['usuario'] ?? '') ?></td>
        <td><?php if ($c['estado'] === 'pendiente' && puede('operador')): ?>
          <form method="post" action="comando.php"><?= csrf_campo() ?>
            <input type="hidden" name="medidor_id" value="<?= $id ?>">
            <button name="cancelar" value="<?= (int) $c['id'] ?>" class="sec">Cancelar</button></form>
        <?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  </section>
</div>

<section class="panel">
  <div class="titulo-fila">
    <h2>Consumo diario (<?= $unidad ?>)</h2>
  </div>
  <div class="grafico"><canvas id="g-diario"></canvas></div>
</section>

<section class="panel">
  <div class="titulo-fila">
    <h2>Acumulado (<?= $unidad ?>)</h2>
    <div class="rangos" id="rangos">
      <?php foreach (['24h' => '24 h', '7d' => '7 días', '30d' => '30 días', '90d' => '90 días'] as $r => $t): ?>
        <button type="button" class="sec" data-rango="<?= $r ?>"><?= $t ?></button>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="grafico"><canvas id="g-acum"></canvas></div>
</section>

<div class="dos">
<section class="panel">
  <h2>Alarmas</h2>
  <?php if (!$alarmas): ?><p class="vacio">Sin alarmas registradas.</p><?php else: ?>
  <table>
    <tr><th>Alarma</th><th>Inicio</th><th>Fin</th></tr>
    <?php foreach ($alarmas as $a): ?>
    <tr><td><span class="chip <?= $a['fin'] ? 'apagado' : 'mal' ?>"><?= h(nombre_alarma($a['codigo'])) ?></span></td>
        <td><?= fmt_fecha($a['inicio']) ?></td>
        <td><?= $a['fin'] ? fmt_fecha($a['fin']) : '<strong>activa</strong>' ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</section>

<section class="panel">
  <div class="titulo-fila">
    <h2>Lecturas recientes</h2>
    <a class="btn sec" href="exportar.php?id=<?= $id ?>">Exportar CSV</a>
  </div>
  <?php if (!$lecturas): ?><p class="vacio">Aún no hay lecturas.</p><?php else: ?>
  <div class="tabla-scroll" style="max-height:420px;overflow-y:auto"><table>
    <tr><th>Fecha</th><th class="n">Acumulado</th><th>Corte</th><th class="n">Bat. V</th><th class="n">RSSI</th></tr>
    <?php foreach ($lecturas as $l): ?>
    <tr title="<?= h($l['raw'] ?? '') ?>"><td><?= fmt_fecha($l['ts']) ?></td><td class="n"><?= fmt_num($l['valor'], $dec) ?></td>
        <td><?= h(nombre_corte($l['corte'])) ?></td><td class="n"><?= fmt_num($l['bateria_v'], 2) ?></td>
        <td class="n"><?= h($l['rssi'] ?? '—') ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</section>
</div>

<script>window.MEDIDOR = { id: <?= $id ?>, unidad: <?= json_encode($unidad) ?>, dec: <?= $dec ?>, tipo: <?= json_encode($m['tipo']) ?> };</script>
<?php pie(['assets/vendor/chart.umd.js?v=4.4.1', 'assets/medidor.js?v=1']);
