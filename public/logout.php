<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
iniciar_sesion();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    if (usuario()) auditar('logout');
    $_SESSION = [];
    session_destroy();
}
redirigir('login.php');
