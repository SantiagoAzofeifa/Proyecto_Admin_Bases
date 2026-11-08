<?php
// Control del Módulo 06 (Laboratorio de índices). Parte de Gabriel:
// limpieza garantizada de la copia, bloqueo de un ensayo a la vez,
// respaldo previo (Módulo 02) y bitácora.
//
// lab_solicitar() envuelve el ensayo de Santiago (lab_ensayar, lab_ensayo.php):
//   1. valida caso, candidato y límite de filas      -> si falla: ERROR, no se toca nada
//   2. toma el bloqueo (un ensayo a la vez)          -> si está ocupado: ERROR
//   3. genera el respaldo previo (Módulo 02)         -> si falla: ERROR, no se crea la copia
//   4. limpia restos de ensayos interrumpidos y ejecuta lab_ensayar()
//   5. SIEMPRE: confirma que la copia sbx_ ya no existe, suelta el bloqueo y registra en la bitácora
//
// Es la única parte del laboratorio que hace DDL, así que la copia se borra por tres vías:
//   a) el finally de lab_ensayar(); b) el finally de esta función (con reconexión si la
//   conexión se cayó); c) un shutdown handler por si PHP muere (tiempo agotado, error fatal).
// Los nombres de tabla salen de lab_catalogo(); nada que escriba el usuario llega al SQL.

const LAB_SEG_MAX_ENSAYO = 300;   // tiempo máximo de PHP para respaldo + ensayo

// ---------------------------------------------------------------------
// Bloqueo: un solo ensayo a la vez en toda la cuenta
// ---------------------------------------------------------------------

function lab_ruta_lock(array $c): string
{
    return $c['DIR_LOGS'] . '/laboratorio.lock';
}

/** Toma el bloqueo; devuelve el recurso o false si hay otro ensayo en curso (o no hay carpeta). */
function lab_bloquear(array $c)
{
    if (!is_dir($c['DIR_LOGS']) && !@mkdir($c['DIR_LOGS'], 0700, true)) {
        return false;
    }
    $ruta  = lab_ruta_lock($c);
    $nuevo = !is_file($ruta);
    $fp    = @fopen($ruta, 'c');
    if ($fp === false) {
        return false;
    }
    if ($nuevo) {
        @chmod($ruta, 0600);
    }
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false;
    }
    return $fp;
}

function lab_desbloquear($fp): void
{
    if (is_resource($fp)) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// ---------------------------------------------------------------------
// Limpieza de copias
// ---------------------------------------------------------------------

/** Copias que el laboratorio puede crear: SOLO sbx_<tabla> de las tablas del catálogo cerrado. */
function lab_copias_posibles(): array
{
    $n = [];
    foreach (lab_catalogo() as $caso) {
        $n[lab_nombre_copia($caso['tabla'])] = true;
    }
    return array_keys($n);
}

/** Copias que existen ahora mismo en la base (restos de un ensayo interrumpido). */
function lab_copias_existentes(PDO $pdo): array
{
    $existen = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
    )->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_intersect(lab_copias_posibles(), $existen));
}

/** Borra restos de ensayos anteriores. Devuelve los nombres borrados. */
function lab_limpiar_restos(PDO $pdo): array
{
    $borradas = [];
    foreach (lab_copias_existentes($pdo) as $copia) {
        $pdo->exec('DROP TABLE IF EXISTS ' . cat_ident($copia));
        $borradas[] = $copia;
    }
    return $borradas;
}

/**
 * Elimina la copia del caso y confirma en information_schema. Si la conexión recibida
 * ya no sirve (por ejemplo "server has gone away"), reintenta con una conexión nueva.
 */
function lab_borrar_copia_seguro(PDO $pdo, array $caso): bool
{
    foreach ([$pdo, null] as $intento) {
        try {
            return lab_eliminar_copia($intento ?? conectar_bd(), $caso);
        } catch (Throwable $ex) {
            // siguiente intento
        }
    }
    return false;
}

// ---------------------------------------------------------------------
// Solicitud completa
// ---------------------------------------------------------------------

/** Texto corto y seguro para mostrar/registrar lo que llegó del formulario. */
function lab_corto($s, int $max = 20): string
{
    return mb_substr(preg_replace('/[\x00-\x1F\x7F|]+/', ' ', (string) $s), 0, $max);
}

/**
 * Atiende una solicitud de ensayo de punta a punta (ver el encabezado del archivo).
 * Siempre devuelve un arreglo compatible con la pantalla (lab_ensayar() + respaldo,
 * bitacora_ok y duracion_total_ms) y siempre deja una línea en la bitácora.
 *
 * @param callable|null $gancho SOLO PARA PRUEBAS: se invoca tras el respaldo y antes del ensayo.
 */
function lab_solicitar(PDO $pdo, array $c, string $casoId, string $candId, ?callable $gancho = null): array
{
    ignore_user_abort(true);              // cerrar el navegador no debe dejar la copia a medias
    @set_time_limit(LAB_SEG_MAX_ENSAYO);
    $t0  = microtime(true);
    $con = monitor_conexion($pdo);
    $r = [
        'ok' => false, 'error' => null, 'caso' => lab_corto($casoId), 'candidato' => lab_corto($candId),
        'inicio' => date('Y-m-d H:i:s P'), 'copia_eliminada' => null, 'respaldo' => null,
        'accion' => 'Ensayo rechazado', 'tabla_log' => '(ninguna)', 'base' => $con['base'],
        'motor' => (string) $c['DB_MOTOR'], 'restos_borrados' => [],
    ];
    $cerrar = function () use (&$r, $c, $t0) {
        $r['duracion_total_ms'] = (int) round((microtime(true) - $t0) * 1000);
        $r['duracion_ms']       = $r['duracion_total_ms'];
        $r['bitacora_ok']       = lab_registrar($c, $r);
        return $r;
    };

    // 1. Lista cerrada y límite de filas: si fallan, no se bloquea, ni se respalda, ni se toca la base.
    $res = lab_resolver($casoId, $candId);
    if ($res === null) {
        $r['error'] = 'Caso o candidato fuera de la lista permitida: no se ejecutó nada.';
        return $cerrar();
    }
    [$caso, $cand] = $res;
    $r['tabla']     = $caso['tabla'];
    $r['tabla_log'] = $caso['tabla'];
    $r['indice']    = $cand['nombre'];
    $r['accion']    = "Ensayo $casoId/$candId";
    [$dentro, $filas, $motivo] = lab_verificar_limite($pdo, $c, $caso);
    $r['filas_origen'] = $filas;
    if (!$dentro) {
        $r['error'] = $motivo;
        return $cerrar();
    }

    // 2. Un ensayo a la vez.
    $lock = lab_bloquear($c);
    if ($lock === false) {
        $r['error'] = 'No se pudo iniciar: hay otro ensayo en curso o falta la carpeta de bitácoras. Intente de nuevo en un momento.';
        return $cerrar();
    }

    // Red de seguridad si PHP muere a media operación (el bloqueo se suelta solo al terminar el proceso).
    $estado = ['activo' => true];
    register_shutdown_function(function () use (&$estado, &$r, $c, $caso, $t0) {
        if ($estado['activo']) {
            $r['ok']              = false;
            $r['copia_eliminada'] = lab_borrar_copia_seguro(conectar_bd(), $caso);
            $r['error']           = 'El proceso terminó antes de acabar el ensayo (tiempo agotado o error fatal).';
            $r['duracion_total_ms'] = (int) round((microtime(true) - $t0) * 1000);
            lab_registrar($c, $r);
        }
    });

    $copiaPosible = false;   // pasa a true cuando ya pudo haberse creado la copia
    try {
        // 3. Respaldo previo (Módulo 02): sin respaldo no hay DDL.
        [$okResp, $msgResp] = respaldos_generar($c);
        if (!$okResp) {
            $r['error'] = 'No se ejecutó el ensayo: falló el respaldo previo (' . lab_corto($msgResp, 160) . ').';
            return $cerrar();
        }
        $r['respaldo'] = lab_corto($msgResp, 120);

        // Desde aquí cualquier salida (error, excepción, tiempo agotado) debe terminar sin copia.
        $copiaPosible = true;
        if ($gancho !== null) {
            $gancho($pdo, $caso);
        }

        // 4. Restos de ensayos interrumpidos y ensayo propiamente dicho.
        $r['restos_borrados'] = lab_limpiar_restos($pdo);
        $e = lab_ensayar($pdo, $c, $casoId, $candId);
        $r = array_merge($r, array_diff_key($e, ['inicio' => 1]));
    } catch (Throwable $ex) {
        $r['ok']    = false;
        $r['error'] = 'El ensayo se detuvo: ' . lab_corto($ex->getMessage(), 200);
    } finally {
        // 5. Limpieza garantizada, pase lo que pase.
        if ($copiaPosible) {
            $borrada = lab_borrar_copia_seguro($pdo, $caso);
            $r['copia_eliminada'] = $borrada;
            if (!$borrada) {
                $r['ok'] = false;
                $r['error'] = trim(($r['error'] ?? '') . ' No se pudo eliminar la copia ' . lab_nombre_copia($caso['tabla']) . ': elimínela a mano.');
            }
        }
        $estado['activo'] = false;
        lab_desbloquear($lock);
    }
    return $cerrar();
}

// ---------------------------------------------------------------------
// Bitácora privada (fuera de public_html, permisos 600)
// Formato: inicio | motor | base | tabla | acción | resultado | detalle
// ---------------------------------------------------------------------

function lab_ruta_log(array $c): string
{
    return $c['DIR_LOGS'] . '/laboratorio.log';
}

function lab_limpio($s, int $max = 300): string
{
    return trim(mb_substr((string) preg_replace('/[\x00-\x1F\x7F|]+/', ' ', (string) $s), 0, $max));
}

/** Escribe una línea; devuelve false si no pudo (la pantalla lo avisa). */
function lab_registrar(array $c, array $r): bool
{
    $partes = [];
    if (!empty($r['error'])) {
        $partes[] = rtrim((string) $r['error'], '. ') . '.';
    }
    if (!empty($r['respaldo'])) {
        $partes[] = 'Respaldo previo: ' . $r['respaldo'] . '.';
    }
    if (isset($r['filas_copia'])) {
        $partes[] = 'Filas copiadas: ' . (int) $r['filas_copia'] . '.';
    }
    if (!empty($r['ok'])) {
        $partes[] = 'Índice usado por el optimizador: ' . (!empty($r['usa_indice']) ? 'sí' : 'no') . '.';
    }
    if (!empty($r['restos_borrados'])) {
        $partes[] = 'Restos de un ensayo anterior eliminados: ' . implode(', ', $r['restos_borrados']) . '.';
    }
    if ($r['copia_eliminada'] !== null) {
        $partes[] = 'Copia eliminada: ' . ($r['copia_eliminada'] ? 'sí' : 'NO') . '.';
    }
    $partes[] = sprintf('Duración: %.3f s.', ($r['duracion_total_ms'] ?? 0) / 1000);

    $linea = implode(' | ', [
        lab_limpio($r['inicio'] ?? date('Y-m-d H:i:s P'), 30),
        lab_limpio($r['motor'] ?? ($c['DB_MOTOR'] ?? 'MariaDB'), 30),
        lab_limpio($r['base'] ?? ($c['DB_NAME'] ?? ''), 64),
        lab_limpio($r['tabla_log'] ?? '(ninguna)', 80),
        lab_limpio($r['accion'] ?? 'Ensayo', 60),
        !empty($r['ok']) ? 'OK' : 'ERROR',
        lab_limpio(implode(' ', $partes)),
    ]) . "\n";

    $dir = dirname(lab_ruta_log($c));
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return false;
    }
    $ruta    = lab_ruta_log($c);
    $nuevo   = !is_file($ruta);
    $escrito = @file_put_contents($ruta, $linea, FILE_APPEND | LOCK_EX) !== false;
    if ($escrito && $nuevo) {
        @chmod($ruta, 0600);
    }
    return $escrito;
}

/** Registra un rechazo hecho antes de llamar a lab_solicitar() (por ejemplo, desde la pantalla de confirmación). */
function lab_registrar_rechazo(array $c, string $base, string $caso, string $cand, string $motivo): bool
{
    return lab_registrar($c, [
        'base' => $base, 'accion' => 'Ensayo rechazado', 'tabla_log' => '(ninguna)',
        'ok' => false, 'error' => $motivo . ' [' . lab_corto($caso) . '/' . lab_corto($cand) . ']',
        'copia_eliminada' => null, 'duracion_total_ms' => 0,
    ]);
}

/** Últimas líneas de la bitácora, la más reciente primero. */
function lab_bitacora(array $c, int $max = 10): array
{
    $ruta = lab_ruta_log($c);
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
    return array_slice(array_reverse($filas), 0, $max);
}
