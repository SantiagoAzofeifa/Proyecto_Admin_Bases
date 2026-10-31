<?php
// Lógica del Módulo 04 · Mantenimiento básico. La vista vive en mantenimiento.php.
// Única acción: ANALYZE TABLE sobre UNA tabla del catálogo autorizado.
// La página recibe solo el nombre de una tabla; se valida contra el catálogo
// (MANT_TABLAS) y contra information_schema antes de tocar el motor.
// Reutiliza monitor_conexion(), monitor_ident(), monitor_tam() y monitor_num().

// Catálogo autorizado: únicas tablas que el módulo lista y puede analizar.
const MANT_TABLAS = [
    'categorias', 'clientes', 'productos', 'ventas', 'detalle_venta', // bd_prueba.sql
    'diag_pedidos',                                                    // Módulo 03
    'mant_prueba',                                                     // Módulo 04 (mant_datos.sql)
];

// Consultas del modulo; se muestran tal cual en la pagina.
const MANT_SQL = [
    'panel' => "SELECT t.TABLE_NAME AS tabla, t.ENGINE AS motor,
       t.TABLE_ROWS AS filas_est,
       t.DATA_LENGTH AS datos, t.INDEX_LENGTH AS indices,
       COUNT(DISTINCT s.INDEX_NAME) AS n_indices
FROM information_schema.TABLES t
LEFT JOIN information_schema.STATISTICS s
       ON s.TABLE_SCHEMA = t.TABLE_SCHEMA AND s.TABLE_NAME = t.TABLE_NAME
WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'
  AND t.TABLE_NAME IN (<tablas autorizadas>)
GROUP BY t.TABLE_NAME, t.ENGINE, t.TABLE_ROWS, t.DATA_LENGTH, t.INDEX_LENGTH
ORDER BY t.TABLE_NAME",

    'existe' => "SELECT COUNT(*) FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'",

    'instantanea' => "SELECT TABLE_ROWS AS filas_est, DATA_LENGTH AS datos, INDEX_LENGTH AS indices
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",

    'cardinalidad' => "SELECT MAX(CARDINALITY) FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",

    'conteo'   => "SELECT COUNT(*) FROM `<tabla>`",
    'analizar' => "ANALYZE TABLE `<tabla>`",
];

/** Panel: solo tablas autorizadas que existen en la base propia. NULL = dato desconocido. */
function mant_panel(PDO $pdo, array $ultimos): array
{
    $marcas = implode(', ', array_fill(0, count(MANT_TABLAS), '?'));
    $st = $pdo->prepare(str_replace('<tablas autorizadas>', $marcas, MANT_SQL['panel']));
    $st->execute(MANT_TABLAS);

    $filas = [];
    foreach ($st->fetchAll() as $t) {
        $filas[] = [
            'tabla'     => $t['tabla'],
            'motor'     => $t['motor'],
            'est'       => $t['filas_est'] === null ? null : (int) $t['filas_est'],
            'datos'     => $t['datos']     === null ? null : (int) $t['datos'],
            'indices'   => $t['indices']   === null ? null : (int) $t['indices'],
            'n_indices' => (int) $t['n_indices'],
            'analisis'  => $ultimos[$t['tabla']] ?? null,   // último ANALYZE OK de la consola
        ];
    }
    return $filas;
}

/** Valida contra el catálogo y contra la base actual. Devuelve [bool, motivo]. */
function mant_validar(PDO $pdo, string $tabla): array
{
    if (!in_array($tabla, MANT_TABLAS, true)) {
        return [false, 'Tabla no permitida: no está en la lista autorizada del módulo.'];
    }
    $st = $pdo->prepare(MANT_SQL['existe']);
    $st->execute([$tabla]);
    if ((int) $st->fetchColumn() === 0) {
        return [false, 'La tabla no existe en la base de datos actual.'];
    }
    return [true, ''];
}

/** Estado de una tabla ya validada: estadísticas del motor y conteo exacto. */
function mant_instantanea(PDO $pdo, string $tabla): array
{
    $st = $pdo->prepare(MANT_SQL['instantanea']);
    $st->execute([$tabla]);
    $r = $st->fetch() ?: [];

    $st = $pdo->prepare(MANT_SQL['cardinalidad']);
    $st->execute([$tabla]);
    $card = $st->fetchColumn();

    $nulo = fn($v) => ($v === null || $v === false) ? null : (int) $v;
    return [
        'est'     => $nulo($r['filas_est'] ?? null),
        'datos'   => $nulo($r['datos'] ?? null),
        'indices' => $nulo($r['indices'] ?? null),
        'card'    => $nulo($card),
        'exactas' => (int) $pdo->query('SELECT COUNT(*) FROM ' . monitor_ident($tabla))->fetchColumn(),
    ];
}

/** ANALYZE TABLE de una tabla validada. Un error o advertencia del motor no es OK. */
function mant_analizar(PDO $pdo, string $tabla): array
{
    $ok = false;
    $problemas = [];
    foreach ($pdo->query('ANALYZE TABLE ' . monitor_ident($tabla))->fetchAll() as $f) {
        $tipo  = strtolower((string) $f['Msg_type']);
        $texto = (string) $f['Msg_text'];
        if ($tipo === 'error' || $tipo === 'warning') {
            $problemas[] = $texto;
        } elseif ($tipo === 'status' && $texto === 'OK') {
            $ok = true;
        }
    }
    if ($problemas) {
        return [false, 'El motor respondió: ' . substr(implode('; ', $problemas), 0, 200)];
    }
    return $ok ? [true, 'ANALYZE TABLE terminó con estado OK.']
               : [false, 'El motor no devolvió el estado OK.'];
}

/** Bloqueo por tabla: evita dos operaciones simultáneas sobre la misma tabla. */
function mant_bloquear(array $c, string $tabla)
{
    // $tabla ya está validada contra el catálogo: es seguro usarla en el nombre.
    $fp = @fopen($c['DIR_LOGS'] . '/mant_' . $tabla . '.lock', 'c');
    if ($fp === false) {
        return false;
    }
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false;
    }
    return $fp;
}

/** Nombre apto para la bitácora (evita inyectar saltos de línea o separadores). */
function mant_tabla_segura(string $t): string
{
    return in_array($t, MANT_TABLAS, true)
        ? $t
        : '(no válida) ' . preg_replace('/[^A-Za-z0-9_]/', '?', substr($t, 0, 40));
}

/** Atiende una solicitud completa: valida, bloquea, ejecuta, contrasta y registra. */
function mant_solicitar(PDO $pdo, array $c, string $tabla): array
{
    $con = monitor_conexion($pdo);
    $r = [
        'inicio'      => date('Y-m-d H:i:s P'),
        'motor'       => (string) $c['DB_MOTOR'],
        'servidor'    => $con['version'],
        'base'        => $con['base'],
        'usuario'     => $con['usuario'],
        'tabla'       => $tabla,
        'tabla_log'   => mant_tabla_segura($tabla),
        'accion'      => 'ANALYZE TABLE',
        'ok'          => false,
        'mensaje'     => '',
        'duracion'    => 0.0,
        'antes'       => null,
        'despues'     => null,
        'conservadas' => null,
    ];
    $t0 = microtime(true);

    [$valida, $motivo] = mant_validar($pdo, $tabla);
    if (!$valida) {
        $r['accion']  = 'Operación rechazada';
        $r['mensaje'] = $motivo;
    } elseif (($lock = mant_bloquear($c, $tabla)) === false) {
        $r['mensaje'] = 'No se pudo iniciar: hay otra operación en curso sobre esta tabla o falta la carpeta de bitácoras.';
    } else {
        try {
            $r['antes'] = mant_instantanea($pdo, $tabla);
            [$r['ok'], $r['mensaje']] = mant_analizar($pdo, $tabla);
            $r['despues']     = mant_instantanea($pdo, $tabla);
            $r['conservadas'] = $r['antes']['exactas'] === $r['despues']['exactas'];
        } catch (PDOException $ex) {
            $r['ok']      = false;
            $r['mensaje'] = 'Error del motor (' . ($ex->errorInfo[1] ?? '?') . '): ' . substr($ex->getMessage(), 0, 160);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    $r['duracion'] = round(microtime(true) - $t0, 3);
    $detalle = $r['mensaje'];
    if ($r['antes'] !== null && $r['despues'] !== null) {
        $detalle .= sprintf(' COUNT(*) antes %d, después %d.', $r['antes']['exactas'], $r['despues']['exactas']);
    }
    $r['detalle']     = sprintf('%s Duración: %.3f s.', $detalle, $r['duracion']);
    $r['bitacora_ok'] = mant_registrar($c, $r);
    return $r;
}

// ---------------------------------------------------------------------
// Bitácora persistente (fuera de public_html, permisos 600)
// Formato: inicio | motor | base | tabla | acción | resultado | detalle
// ---------------------------------------------------------------------

function mant_ruta_log(array $c): string
{
    return $c['DIR_LOGS'] . '/mantenimiento.log';
}

function mant_registrar(array $c, array $r): bool
{
    $limpio = fn($s) => trim(str_replace(['|', "\r", "\n"], ' ', (string) $s));
    $ruta   = mant_ruta_log($c);
    $nuevo  = !is_file($ruta);
    $linea  = implode(' | ', [
        $r['inicio'], $limpio($r['motor']), $limpio($r['base']), $limpio($r['tabla_log']),
        $limpio($r['accion']), $r['ok'] ? 'OK' : 'ERROR', $limpio($r['detalle']),
    ]) . "\n";

    $escrito = @file_put_contents($ruta, $linea, FILE_APPEND | LOCK_EX) !== false;
    if ($escrito && $nuevo) {
        @chmod($ruta, 0600);
    }
    return $escrito;
}

/** Todas las líneas de la bitácora, la más reciente primero. */
function mant_bitacora(array $c): array
{
    $ruta = mant_ruta_log($c);
    if (!is_readable($ruta)) {
        return [];
    }
    $filas = [];
    foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $p = array_map('trim', explode(' | ', $l, 7));
        if (count($p) === 7) {
            $filas[] = ['fecha' => $p[0], 'motor' => $p[1], 'base' => $p[2], 'tabla' => $p[3],
                        'accion' => $p[4], 'resultado' => $p[5], 'detalle' => $p[6]];
        }
    }
    return array_reverse($filas);
}

/** Fecha del último ANALYZE exitoso registrado por la consola, por tabla. */
function mant_ultimos_analisis(array $bitacora): array
{
    $u = [];
    foreach ($bitacora as $b) {   // la más reciente primero
        if ($b['accion'] === 'ANALYZE TABLE' && $b['resultado'] === 'OK' && !isset($u[$b['tabla']])) {
            $u[$b['tabla']] = $b['fecha'];
        }
    }
    return $u;
}

// Un dato desconocido se muestra como "No disponible", nunca como un cero inventado.
function mant_num(?int $n): string
{
    return $n === null ? 'No disponible' : monitor_num($n);
}

function mant_tam(?int $n): string
{
    return $n === null ? 'No disponible' : monitor_tam($n);
}
