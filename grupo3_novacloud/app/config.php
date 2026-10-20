<?php
/**
 * Cargador de configuración central - Consola DBA v1.0
 * Se copia dentro de la carpeta web de la app (ej. ~/public_html/dba/config.php).
 *
 * Es GENÉRICO: no tiene usuario, base, ruta ni clave escritas. Todo sale de
 *   ~/dba_config/dba.conf   (fuera de public_html)
 *
 * - El HOME se deduce subiendo desde este archivo hasta la carpeta "public_html";
 *   funciona con cualquier nombre de carpeta de la app (dba, proyecto, consola...).
 * - Si se necesita otra ubicación para el .conf, definir la variable de entorno
 *   DBA_CONF con la ruta completa.
 *
 * Uso en las demás páginas:
 *   require_once __DIR__ . '/config.php';
 *   $cfg = cargar_config();
 *   $pdo = conectar_bd();
 */

/** HOME de la cuenta de Virtualmin: la carpeta que contiene a public_html. */
function dba_home(): string
{
    $dir = __DIR__;
    while ($dir !== dirname($dir)) {
        if (basename($dir) === 'public_html') {
            return dirname($dir);
        }
        $dir = dirname($dir);
    }

    // Respaldo: directorio home del usuario Linux que ejecuta PHP
    if (function_exists('posix_getpwuid')) {
        $info = posix_getpwuid(posix_geteuid());
        if (!empty($info['dir'])) {
            return $info['dir'];
        }
    }
    return rtrim((string) getenv('HOME'), '/');
}

function dba_ruta_conf(): string
{
    $env = getenv('DBA_CONF');
    return ($env !== false && $env !== '') ? $env : dba_home() . '/dba_config/dba.conf';
}

function cargar_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $archivo = dba_ruta_conf();
    if (!is_readable($archivo)) {
        http_response_code(500);
        exit('Error de configuración: no se puede leer el archivo de configuración.');
    }

    $cfg = parse_ini_file($archivo, false, INI_SCANNER_RAW);
    if ($cfg === false) {
        http_response_code(500);
        exit('Error de configuración: archivo de configuración inválido.');
    }

    // Valores por defecto para claves opcionales
    $cfg += [
        'DB_MOTOR'              => 'MariaDB',
        'DB_HOST'               => 'localhost',
        'DB_PORT'               => '3306',
        'APP_VERSION'           => '1.0',
        'APP_NOMBRE'            => 'Consola DBA',
        'RUTA_SCRIPTS'          => 'dba_scripts',
        'RUTA_RESPALDOS'        => 'respaldos',
        'RUTA_LOGS'             => 'dba_logs',
        'ARCHIVO_LOG_RESPALDOS' => 'dba_logs/respaldos.log',
    ];

    // Rutas absolutas derivadas del HOME (nada "quemado")
    $home = dba_home();
    $cfg['HOME']                  = $home;
    $cfg['DIR_SCRIPTS']           = $home . '/' . $cfg['RUTA_SCRIPTS'];
    $cfg['DIR_RESPALDOS']         = $home . '/' . $cfg['RUTA_RESPALDOS'];
    $cfg['DIR_LOGS']              = $home . '/' . $cfg['RUTA_LOGS'];
    $cfg['ARCHIVO_LOG_RESPALDOS'] = $home . '/' . $cfg['ARCHIVO_LOG_RESPALDOS'];

    // Claves obligatorias
    foreach (['DB_NAME', 'DB_USER', 'DB_PASS', 'ADMIN_USER', 'ADMIN_HASH'] as $k) {
        if (!isset($cfg[$k]) || $cfg[$k] === '' || strpos($cfg[$k], 'CAMBIAR') === 0) {
            http_response_code(500);
            exit('Error de configuración: falta definir ' . htmlspecialchars($k) . ' en dba.conf.');
        }
    }

    return $cfg;
}

/** Conexión PDO a la base del propio estudiante (MariaDB). */
function conectar_bd(): PDO
{
    $c   = cargar_config();
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $c['DB_HOST'], $c['DB_PORT'], $c['DB_NAME']
    );

    return new PDO($dsn, $c['DB_USER'], $c['DB_PASS'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}
