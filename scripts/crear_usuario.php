<?php
// Crea (o restablece) un usuario desde la consola. Útil para el primer administrador.
//   php scripts/crear_usuario.php <usuario> <rol: admin|operador|lector> ["Nombre"]
// La contraseña se pide por teclado (no queda en el historial del shell).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../src/bootstrap.php';

[$_, $usuario, $rol, $nombre] = $argv + [null, null, null, ''];
if (!$usuario || !isset(ROLES[$rol ?? ''])) {
    fwrite(STDERR, "Uso: php scripts/crear_usuario.php <usuario> <admin|operador|lector> [\"Nombre\"]\n");
    exit(1);
}
fwrite(STDOUT, 'Contraseña (mín. 8): ');
system('stty -echo 2>/dev/null');
$clave = trim((string) fgets(STDIN));
system('stty echo 2>/dev/null');
fwrite(STDOUT, "\n");
if (strlen($clave) < 8) { fwrite(STDERR, "Contraseña demasiado corta.\n"); exit(1); }

q('INSERT INTO usuarios (usuario, nombre, rol, clave_hash) VALUES (?, ?, ?, ?)
   ON CONFLICT (usuario) DO UPDATE SET rol = EXCLUDED.rol, clave_hash = EXCLUDED.clave_hash, activo = true,
     nombre = CASE WHEN EXCLUDED.nombre = \'\' THEN usuarios.nombre ELSE EXCLUDED.nombre END',
  [$usuario, $nombre, $rol, password_hash($clave, PASSWORD_DEFAULT)]);
echo "Usuario '$usuario' ($rol) listo.\n";
