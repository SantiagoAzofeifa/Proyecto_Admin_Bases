<?php
// Funciones de autenticación y sesión compartidas por todas las páginas.

const APP_VERSION = '1.0';
const CURSO       = 'Administración de Bases de Datos';
const PROFESOR    = 'Manuel Espinoza G.';

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
    $usuarios = require __DIR__ . '/../config/usuarios.php';
    $hash = $usuarios[$usuario] ?? null;

    // Si el usuario no existe se verifica contra un hash falso para no revelar tiempos distintos.
    if ($hash === null) {
        password_verify($clave, '$2y$10$usesomesillystringforsalt$');
        return false;
    }
    return password_verify($clave, $hash);
}

function usuario_actual(): ?string
{
    iniciar_sesion();
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

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
