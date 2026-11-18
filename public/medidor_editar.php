<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
requerir('admin');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$m = $id ? q('SELECT * FROM medidores WHERE id = ?', [$id])->fetch() : null;
if ($id && !$m) { http_response_code(404); exit('Medidor no encontrado.'); }
$m ??= ['tipo' => 'agua', 'numero' => '', 'dispositivo_id' => '', 'nombre' => '', 'ubicacion' => '',
        'comunicacion' => '', 'modelo' => '', 'tiene_corte' => false, 'activo' => true];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    if (isset($_POST['eliminar']) && $id) {
        q('DELETE FROM medidores WHERE id = ?', [$id]);
        auditar('medidor_eliminar', $m['numero']);
        flash('Medidor eliminado junto con sus lecturas.');
        redirigir('medidores.php');
    }
    $f = [
        'tipo'           => (string) ($_POST['tipo'] ?? ''),
        'numero'         => trim((string) ($_POST['numero'] ?? '')),
        'dispositivo_id' => strtoupper(trim((string) ($_POST['dispositivo_id'] ?? ''))) ?: null,
        'nombre'         => trim((string) ($_POST['nombre'] ?? '')),
        'ubicacion'      => trim((string) ($_POST['ubicacion'] ?? '')),
        'comunicacion'   => trim((string) ($_POST['comunicacion'] ?? '')),
        'modelo'         => trim((string) ($_POST['modelo'] ?? '')),
        'tiene_corte'    => isset($_POST['tiene_corte']) ? 'true' : 'false',
        'activo'         => isset($_POST['activo']) ? 'true' : 'false',
    ];
    if (!isset(TIPOS[$f['tipo']]) || $f['numero'] === '') {
        $error = 'Tipo y número de medidor son obligatorios.';
    } else {
        try {
            if ($id) {
                q('UPDATE medidores SET tipo=?, numero=?, dispositivo_id=?, nombre=?, ubicacion=?, comunicacion=?,
                   modelo=?, tiene_corte=?, activo=? WHERE id=?', [...array_values($f), $id]);
                auditar('medidor_editar', $f['numero']);
            } else {
                $id = (int) q('INSERT INTO medidores (tipo, numero, dispositivo_id, nombre, ubicacion, comunicacion,
                               modelo, tiene_corte, activo) VALUES (?,?,?,?,?,?,?,?,?) RETURNING id',
                              array_values($f))->fetchColumn();
                auditar('medidor_crear', $f['numero']);
            }
            flash('Medidor guardado.');
            redirigir('medidor.php?id=' . $id);
        } catch (PDOException $e) {
            $error = $e->getCode() === '23505' ? 'Ya existe un medidor con ese número o ID de dispositivo.' : 'Error al guardar.';
            $m = array_merge($m, $f, ['tiene_corte' => $f['tiene_corte'] === 'true', 'activo' => $f['activo'] === 'true']);
        }
    }
}

cabecera($id ? 'Editar medidor' : 'Nuevo medidor', 'medidores.php');
?>
<h1><?= $id ? 'Editar medidor' : 'Nuevo medidor' ?></h1>
<?php if ($error): ?><div class="aviso error"><?= h($error) ?></div><?php endif; ?>
<form method="post" class="panel" style="max-width:640px">
  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>">
  <div class="dos">
    <div class="campo"><label>Tipo</label>
      <select name="tipo"><?php foreach (TIPOS as $t => $n): ?>
        <option value="<?= $t ?>" <?= $m['tipo'] === $t ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></div>
    <div class="campo"><label>Nº de medidor (serie)</label><input name="numero" value="<?= h($m['numero']) ?>" required></div>
    <div class="campo"><label>ID de dispositivo (IMEI / ID trama / DevEUI)</label>
      <input name="dispositivo_id" value="<?= h($m['dispositivo_id'] ?? '') ?>"></div>
    <div class="campo"><label>Nombre</label><input name="nombre" value="<?= h($m['nombre']) ?>"></div>
    <div class="campo"><label>Ubicación</label><input name="ubicacion" value="<?= h($m['ubicacion']) ?>"></div>
    <div class="campo"><label>Comunicación</label><input name="comunicacion" value="<?= h($m['comunicacion']) ?>" placeholder="WiFi, 4G, LoRaWAN…"></div>
    <div class="campo"><label>Modelo</label><input name="modelo" value="<?= h($m['modelo']) ?>"></div>
  </div>
  <div class="campo"><label><input type="checkbox" name="tiene_corte" <?= $m['tiene_corte'] ? 'checked' : '' ?>> Tiene válvula / relé controlable</label></div>
  <div class="campo"><label><input type="checkbox" name="activo" <?= $m['activo'] ? 'checked' : '' ?>> Activo (se muestra en el panel y guarda historial)</label></div>
  <button type="submit">Guardar</button>
  <a class="btn sec" href="<?= $id ? 'medidor.php?id=' . $id : 'medidores.php' ?>">Cancelar</a>
  <?php if ($id): ?>
    <button name="eliminar" value="1" class="peligro" style="float:right"
            onclick="return confirm('¿Eliminar el medidor y TODO su historial? Para conservarlo, márquelo como inactivo.')">Eliminar</button>
  <?php endif; ?>
</form>
<?php pie();
