<?php
require_once __DIR__ . '/../config.php';

const APP_VERSION = '2.0';
const APP_NOMBRE  = 'Consola DBA';
const CURSO       = 'Administración de Bases de Datos';
const PROFESOR    = 'Manuel Espinoza G.';

// Segundos de inactividad antes de cerrar la sesión automáticamente.
const SESION_INACTIVIDAD = 1800; // 30 minutos

function iniciar_sesion(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
        ]);
        session_start();
    }
}

function validar_credenciales(string $usuario, string $clave): bool
{
    $c = cargar_config();

    // Se ejecutan SIEMPRE las dos comprobaciones: así el tiempo de respuesta
    // no revela si el usuario existe.
    $usuario_ok = hash_equals((string) $c['ADMIN_USER'], $usuario);
    $clave_ok   = password_verify($clave, (string) $c['ADMIN_HASH']);

    return $usuario_ok && $clave_ok;
}

function usuario_actual(): ?string
{
    iniciar_sesion();

    // Cierre por inactividad.
    if (isset($_SESSION['usuario'], $_SESSION['ultimo_acceso'])
        && (time() - $_SESSION['ultimo_acceso']) > SESION_INACTIVIDAD) {
        cerrar_sesion();
        iniciar_sesion();
        return null;
    }
    if (isset($_SESSION['usuario'])) {
        $_SESSION['ultimo_acceso'] = time();
    }
    return $_SESSION['usuario'] ?? null;
}

function requerir_login(): void
{
    if (usuario_actual() === null) {
        header('Location: login.php');
        exit;
    }
}

function cerrar_sesion(): void
{
    iniciar_sesion();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// Token CSRF para el formulario de login.
function csrf_token(): string
{
    iniciar_sesion();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valido(?string $token): bool
{
    iniciar_sesion();
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}