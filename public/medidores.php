<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
requerir('admin');

$medidores = q('SELECT m.*, (SELECT count(*) FROM lecturas l WHERE l.medidor_id = m.id) AS n_lecturas
                FROM medidores m ORDER BY m.activo DESC, m.tipo, m.numero')->fetchAll();
cabecera('Medidores', 'medidores.php');
?>
<div class="titulo-fila">
  <h1>Medidores</h1>
  <a class="btn" href="medidor_editar.php">+ Agregar medidor</a>
</div>
<p class="quien">Los medidores que reportan con un ID desconocido se dan de alta automáticamente como «Nuevo (alta automática)».</p>
<section class="panel">
  <div class="tabla-scroll"><table>
    <tr><th>Tipo</th><th>Nº medidor</th><th>ID dispositivo</th><th>Nombre</th><th>Comunicación</th><th>Corte</th>
        <th>Último reporte</th><th class="n">Lecturas</th><th>Estado</th><th></th></tr>
    <?php foreach ($medidores as $m): ?>
    <tr>
      <td><?= h(TIPOS[$m['tipo']]) ?></td>
      <td><a href="medidor.php?id=<?= (int) $m['id'] ?>"><code><?= h($m['numero']) ?></code></a></td>
      <td><code><?= h($m['dispositivo_id'] ?? '—') ?></code></td>
      <td><?= h($m['nombre']) ?></td>
      <td><?= h($m['comunicacion']) ?></td>
      <td><?= $m['tiene_corte'] ? 'Sí' : 'No' ?></td>
      <td><?= h(hace($m['ultima_conexion'])) ?></td>
      <td class="n"><?= number_format((int) $m['n_lecturas'], 0, ',', '.') ?></td>
      <td><?= $m['activo'] ? '<span class="chip ok">Activo</span>' : '<span class="chip apagado">Inactivo</span>' ?></td>
      <td><a href="medidor_editar.php?id=<?= (int) $m['id'] ?>">Editar</a></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</section>
<?php pie();
