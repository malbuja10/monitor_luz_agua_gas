<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$yo = requerir('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $usuario = trim((string) ($_POST['usuario'] ?? ''));
    $nombre = trim((string) ($_POST['nombre'] ?? ''));
    $rol = (string) ($_POST['rol'] ?? 'lector');
    $clave = (string) ($_POST['clave'] ?? '');
    $activo = isset($_POST['activo']) ? 'true' : 'false';

    if (!isset(ROLES[$rol]) || ($id === 0 && (!preg_match('/^[a-z0-9._-]{3,50}$/i', $usuario) || strlen($clave) < 8))) {
        flash('Datos inválidos: usuario de 3–50 caracteres (letras, números . _ -) y contraseña de 8+ caracteres.', 'error');
    } elseif ($clave !== '' && strlen($clave) < 8) {
        flash('La contraseña debe tener al menos 8 caracteres.', 'error');
    } elseif ($id === $yo['id'] && ($rol !== 'admin' || $activo === 'false')) {
        flash('No puede quitarse a sí mismo el rol de administrador ni desactivarse.', 'error');
    } else {
        try {
            if ($id) {
                q('UPDATE usuarios SET nombre = ?, rol = ?, activo = ? WHERE id = ?', [$nombre, $rol, $activo, $id]);
                if ($clave !== '') q('UPDATE usuarios SET clave_hash = ? WHERE id = ?', [password_hash($clave, PASSWORD_DEFAULT), $id]);
                auditar('usuario_editar', "id $id rol $rol" . ($clave !== '' ? ' (cambio de clave)' : ''));
            } else {
                q('INSERT INTO usuarios (usuario, nombre, rol, clave_hash, activo) VALUES (?, ?, ?, ?, ?)',
                  [$usuario, $nombre, $rol, password_hash($clave, PASSWORD_DEFAULT), $activo]);
                auditar('usuario_crear', "$usuario ($rol)");
            }
            flash('Usuario guardado.');
        } catch (PDOException $e) {
            flash($e->getCode() === '23505' ? 'Ese nombre de usuario ya existe.' : 'Error al guardar.', 'error');
        }
    }
    redirigir('usuarios.php');
}

$usuarios = q('SELECT id, usuario, nombre, rol, activo, ultimo_acceso FROM usuarios ORDER BY usuario')->fetchAll();
cabecera('Usuarios', 'usuarios.php');
?>
<h1>Usuarios</h1>
<p class="quien"><strong>lector</strong>: ve panel, historial y alarmas · <strong>operador</strong>: además envía comandos y reconoce alarmas ·
  <strong>admin</strong>: además gestiona medidores y usuarios.</p>
<section class="panel">
  <div class="tabla-scroll"><table>
    <tr><th>Usuario</th><th>Nombre</th><th>Rol</th><th>Nueva contraseña</th><th>Activo</th><th>Último acceso</th><th></th></tr>
    <?php foreach ($usuarios as $u): $f = 'f' . $u['id']; ?>
    <tr>
      <td><code><?= h($u['usuario']) ?></code></td>
      <td><input form="<?= $f ?>" name="nombre" value="<?= h($u['nombre']) ?>"></td>
      <td><select form="<?= $f ?>" name="rol"><?php foreach (ROLES as $r => $_): ?>
        <option <?= $u['rol'] === $r ? 'selected' : '' ?>><?= $r ?></option><?php endforeach; ?></select></td>
      <td><input form="<?= $f ?>" name="clave" type="password" placeholder="(sin cambio)" autocomplete="new-password"></td>
      <td><input form="<?= $f ?>" name="activo" type="checkbox" <?= $u['activo'] ? 'checked' : '' ?>></td>
      <td><?= h(hace($u['ultimo_acceso'])) ?></td>
      <td><form id="<?= $f ?>" method="post"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
          <button class="sec">Guardar</button></form></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</section>

<h2>Nuevo usuario</h2>
<form method="post" class="panel fila">
  <?= csrf_campo() ?>
  <div><label>Usuario</label><input name="usuario" required pattern="[A-Za-z0-9._\-]{3,50}"></div>
  <div><label>Nombre</label><input name="nombre"></div>
  <div><label>Rol</label><select name="rol"><?php foreach (ROLES as $r => $_): ?><option><?= $r ?></option><?php endforeach; ?></select></div>
  <div><label>Contraseña</label><input name="clave" type="password" minlength="8" required autocomplete="new-password"></div>
  <input type="hidden" name="activo" value="1">
  <button>Crear</button>
</form>
<?php pie();
