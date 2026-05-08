<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
    ]);
}

function requireLogin(string $redirect = 'index.php'): void
{
    if (empty($_SESSION['user_id'])) {
        header("Location: $redirect");
        exit;
    }
}

/**
 * Página de inicio según rol del usuario.
 * Admin → dashboard de usuarios; cualquier otro rol → catálogo.
 * Se usa tanto al iniciar sesión como al rebotar usuarios sin permisos.
 */
function homePage(string $tipo = ''): string
{
    $tipo = $tipo !== '' ? $tipo : ($_SESSION['user_tipo'] ?? '');
    return $tipo === 'administrador' ? 'dashboard.php' : 'catalogSettings.php';
}

function requireRole(array $roles, string $redirect = ''): void
{
    requireLogin();
    $tipo = $_SESSION['user_tipo'] ?? '';
    if (!in_array($tipo, $roles, true)) {
        // Si no se pasa $redirect explícito, mandar al home del rol actual
        // (nunca a la misma página → evita ERR_TOO_MANY_REDIRECTS).
        $target = $redirect !== '' ? $redirect : homePage($tipo);
        header("Location: $target?error=forbidden");
        exit;
    }
}

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

function currentUser(): array
{
    return [
        'id'     => $_SESSION['user_id']     ?? null,
        'nombre' => $_SESSION['user_nombre'] ?? '',
        'tipo'   => $_SESSION['user_tipo']   ?? '',
        'email'  => $_SESSION['user_email']  ?? '',
    ];
}

function setUserSession(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']     = (int) $user['id_usuario'];
    $_SESSION['user_nombre'] = $user['nombre_completo'];
    $_SESSION['user_tipo']   = $user['tipo'];
    $_SESSION['user_email']  = $user['email'];
}

function destroySession(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
