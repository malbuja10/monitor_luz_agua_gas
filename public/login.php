<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
iniciar_sesion();

$volver = $_GET['volver'] ?? $_POST['volver'] ?? 'index.php';
// Solo rutas locales relativas (evita redirecciones abiertas).
if (!is_string($volver) || !preg_match('#^/?[a-z_]+\.php(\?[^\s]*)?$#i', $volver)) $volver = 'index.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    if (intentar_login(trim((string) ($_POST['usuario'] ?? '')), (string) ($_POST['clave'] ?? ''))) {
        auditar('login');
        redirigir($volver);
    }
    $error = 'Usuario o contraseña incorrectos.';
}
cabecera('Ingresar');
?>
<div class="panel login">
  <h1>Ingresar</h1>
  <?php if ($error): ?><div class="aviso error"><?= h($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_campo() ?>
    <input type="hidden" name="volver" value="<?= h($volver) ?>">
    <div class="campo"><label for="u">Usuario</label>
      <input id="u" name="usuario" autocomplete="username" required autofocus></div>
    <div class="campo"><label for="c">Contraseña</label>
      <input id="c" name="clave" type="password" autocomplete="current-password" required></div>
    <button type="submit">Ingresar</button>
  </form>
</div>
<?php pie();
