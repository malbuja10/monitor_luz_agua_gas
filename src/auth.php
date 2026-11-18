<?php
declare(strict_types=1);

const ROLES = ['lector' => 1, 'operador' => 2, 'admin' => 3];

function iniciar_sesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('monitor_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => rtrim((string) config('ruta_base', ''), '/') . '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function usuario(): ?array
{
    return $_SESSION['usuario'] ?? null;
}

function puede(string $rol): bool
{
    $u = usuario();
    return $u && ROLES[$u['rol']] >= ROLES[$rol];
}

/** Exige sesión (y rol mínimo). Para páginas HTML redirige a login; para API responde 401/403. */
function requerir(string $rol = 'lector', bool $api = false): array
{
    iniciar_sesion();
    $u = usuario();
    if (!$u) {
        if ($api) json_out(['error' => 'no autenticado'], 401);
        $volver = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php')
                . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
        redirigir('login.php?volver=' . urlencode($volver));
    }
    if (!puede($rol)) {
        if ($api) json_out(['error' => 'sin permiso'], 403);
        http_response_code(403);
        exit('No tiene permiso para esta página.');
    }
    return $u;
}

function intentar_login(string $usuario, string $clave): bool
{
    $u = q('SELECT id, usuario, nombre, rol, clave_hash FROM usuarios WHERE usuario = ? AND activo',
           [$usuario])->fetch();
    if (!$u || !password_verify($clave, $u['clave_hash'])) {
        usleep(400000); // frena fuerza bruta
        return false;
    }
    if (password_needs_rehash($u['clave_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE usuarios SET clave_hash = ? WHERE id = ?', [password_hash($clave, PASSWORD_DEFAULT), $u['id']]);
    }
    session_regenerate_id(true);
    unset($u['clave_hash']);
    $_SESSION['usuario'] = $u;
    q('UPDATE usuarios SET ultimo_acceso = now() WHERE id = ?', [$u['id']]);
    return true;
}

function csrf_token(): string
{
    iniciar_sesion();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function verificar_csrf(): void
{
    $t = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(400);
        exit('Token CSRF inválido. Recargue la página.');
    }
}

function flash(?string $msg = null, string $tipo = 'ok'): ?array
{
    iniciar_sesion();
    if ($msg !== null) {
        $_SESSION['flash'] = [$msg, $tipo];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
