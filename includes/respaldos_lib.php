<?php
// Logica del modulo de respaldos (Proyecto 02). La vista vive en respaldos.php.
// Recibe el arreglo de cargar_config(); no vuelve a leer ni a derivar rutas.

// Unica accion permitida: ejecutar el script autorizado. Ningun dato del
// usuario entra al comando; ambas rutas salen de la configuracion.
function respaldos_generar(array $c): array
{
    if (!function_exists('exec')) {
        return [false, 'exec() está deshabilitado en PHP.'];
    }
    $script = $c['DIR_SCRIPTS'] . '/backup.sh';
    if (!is_executable($script)) {
        return [false, 'backup.sh no existe o no tiene permiso de ejecución.'];
    }
    exec(escapeshellarg($script) . ' ' . escapeshellarg(dba_ruta_conf()) . ' 2>&1',
         $salida, $codigo);
    return [$codigo === 0, trim(implode("\n", $salida))];
}

// Todas las lineas de la bitacora, la mas reciente primero.
function respaldos_bitacora(array $c): array
{
    if (!is_readable($c['ARCHIVO_LOG_RESPALDOS'])) {
        return [];
    }
    $filas = [];
    $lineas = file($c['ARCHIVO_LOG_RESPALDOS'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lineas as $l) {
        $p = array_map('trim', explode('|', $l, 4));
        if (count($p) >= 3) {
            $filas[] = ['fecha' => $p[0], 'archivo' => $p[1],
                        'estado' => $p[2], 'detalle' => $p[3] ?? ''];
        }
    }
    return array_reverse($filas);
}

// Respaldos existentes. OK = tiene tamano y la bitacora lo registro�como OK.
function respaldos_listar(array $c, array $bitacora): array
{
    $ok = [];
    foreach ($bitacora as $b) {
        if ($b['estado'] === 'OK') {
            $ok[$b['archivo']] = true;
        }
    }
    $filas = [];
    foreach (glob($c['DIR_RESPALDOS'] . '/respaldo_*.sql.gz') ?: [] as $ruta) {
        $nombre = basename($ruta);
        $bytes  = filesize($ruta);
        $filas[] = [
            'archivo' => $nombre,
            'fecha'   => filemtime($ruta),
            'bytes'   => $bytes,
            'estado'  => ($bytes > 0 && isset($ok[$nombre])) ? 'OK' : 'REVISAR',
        ];
    }
    usort($filas, fn($a, $b) => $b['fecha'] <=> $a['fecha']);
    return $filas;
}

function respaldos_tam(int $bytes): string
{
    return $bytes >= 1048576
        ? number_format($bytes / 1048576, 1) . ' MB'
        : number_format(max($bytes / 1024, 1), 0) . ' KB';
}
