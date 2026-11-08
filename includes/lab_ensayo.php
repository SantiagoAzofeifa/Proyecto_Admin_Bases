<?php
// Ensayo del Módulo 06 (Laboratorio de índices). Santiago Azofeifa.
// Copia la tabla del caso en sbx_<tabla>, crea en la COPIA el índice candidato
// y compara el plan antes y después reutilizando las funciones del Módulo 03.
// La tabla original nunca se modifica y la copia se elimina siempre, aun si
// el ensayo falla a la mitad.
//
// Usa: catalogo_lib.php (cat_ident, cat_tabla_existe), lab_catalogo.php (lista
// cerrada, límite y nombres), diag_catalogo.php (SQL de Q01/Q02) y
// diag_lib.php (EXPLAIN, interpretación y comparación).
// El bloqueo de un ensayo a la vez, la bitácora y el respaldo previo van
// alrededor de lab_ensayar() (parte de Gabriel); esta función no los repite.

/** Consulta resumen del caso apuntando a la copia (para comparar resultados). */
function lab_sql_resumen(array $caso): ?string
{
    $d = diag_caso($caso['id']);
    if ($d === null) {
        return null;
    }
    return preg_replace('/\b' . preg_quote($caso['tabla'], '/') . '\b/',
                        cat_ident(lab_nombre_copia($caso['tabla'])), $d['resumen']);
}

/** ANALYZE TABLE sobre la copia; un mensaje de error del motor no se toma como OK. */
function lab_analizar(PDO $pdo, string $tabla): void
{
    foreach ($pdo->query('ANALYZE TABLE ' . cat_ident($tabla))->fetchAll() as $f) {
        if (strcasecmp((string) $f['Msg_type'], 'error') === 0) {
            throw new RuntimeException("ANALYZE TABLE sobre $tabla: {$f['Msg_text']}");
        }
    }
}

/**
 * Crea sbx_<tabla> con la misma estructura y los mismos datos que la original
 * y le quita los índices secundarios heredados, para que el "antes" sea solo
 * con la llave primaria (por ejemplo, el idx_diag_estado_fecha del Módulo 03).
 */
function lab_crear_copia(PDO $pdo, array $c, array $caso): array
{
    $orig  = $caso['tabla'];
    $copia = lab_nombre_copia($orig);
    $pdo->exec('DROP TABLE IF EXISTS ' . cat_ident($copia));     // restos de un ensayo interrumpido
    $pdo->exec('CREATE TABLE ' . cat_ident($copia) . ' LIKE ' . cat_ident($orig));
    $pdo->exec('INSERT INTO ' . cat_ident($copia) . ' SELECT * FROM ' . cat_ident($orig)
             . ' LIMIT ' . lab_max_filas($c));

    $quitados = lab_indices_a_quitar($pdo, $orig);
    foreach ($quitados as $idx) {
        $pdo->exec('DROP INDEX ' . cat_ident($idx) . ' ON ' . cat_ident($copia));
    }
    lab_analizar($pdo, $copia);

    return [
        'copia'            => $copia,
        'filas'            => (int) $pdo->query('SELECT COUNT(*) FROM ' . cat_ident($copia))->fetchColumn(),
        'indices_quitados' => $quitados,
    ];
}

/** Elimina la copia y confirma en information_schema que ya no existe. */
function lab_eliminar_copia(PDO $pdo, array $caso): bool
{
    $copia = lab_nombre_copia($caso['tabla']);
    $pdo->exec('DROP TABLE IF EXISTS ' . cat_ident($copia));
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $st->execute([$copia]);
    return (int) $st->fetchColumn() === 0;
}

/**
 * Ensayo completo de un candidato de la lista cerrada.
 * Devuelve un arreglo con ok/error y, si llegó a ejecutarse, los planes
 * antes y después, su interpretación, las diferencias, los resúmenes y si
 * la copia quedó eliminada. Un caso o candidato fuera de la lista se rechaza
 * sin ejecutar ninguna sentencia.
 */
function lab_ensayar(PDO $pdo, array $c, string $casoId, string $candId): array
{
    $inicio = microtime(true);
    $r = [
        'ok' => false, 'error' => null, 'caso' => $casoId, 'candidato' => $candId,
        'inicio' => date('Y-m-d H:i:s T'), 'copia_eliminada' => null,
    ];
    $fin = function () use (&$r, $inicio) {
        $r['duracion_ms'] = (int) round((microtime(true) - $inicio) * 1000);
        return $r;
    };

    $res = lab_resolver($casoId, $candId);
    if ($res === null) {
        $r['error'] = 'Caso o candidato fuera de la lista permitida: no se ejecutó nada.';
        return $fin();
    }
    [$caso, $cand] = $res;
    $r['indice'] = $cand['nombre'];
    $r['tabla']  = $caso['tabla'];
    $sqlQ = lab_sql_consulta($caso);
    $sqlR = lab_sql_resumen($caso);
    if ($sqlQ === null || $sqlR === null) {
        $r['error'] = 'El caso no existe en el catálogo del Módulo 03.';
        return $fin();
    }
    $r['sql_consulta'] = $sqlQ;
    $r['sql_indice']   = lab_sql_crear_indice($caso, $cand);

    [$dentro, $filas, $motivo] = lab_verificar_limite($pdo, $c, $caso);
    $r['filas_origen'] = $filas;
    if (!$dentro) {
        $r['error'] = $motivo;
        return $fin();
    }

    try {
        $copia = lab_crear_copia($pdo, $c, $caso);
        $r['copia']            = $copia['copia'];
        $r['filas_copia']      = $copia['filas'];
        $r['indices_quitados'] = $copia['indices_quitados'];

        $r['plan_antes']    = diag_explain($pdo, $sqlQ);
        $r['resumen_antes'] = diag_resumen($pdo, $sqlR);

        $pdo->exec($r['sql_indice']);
        lab_analizar($pdo, $copia['copia']);

        $r['plan_despues']    = diag_explain($pdo, $sqlQ);
        $r['resumen_despues'] = diag_resumen($pdo, $sqlR);

        $r['interp_antes']   = diag_interpretar($r['plan_antes']);
        $r['interp_despues'] = diag_interpretar($r['plan_despues']);
        $r['diferencias']    = diag_comparar($r['plan_antes'], $r['plan_despues']);
        $r['resumen_igual']  = diag_resumen_igual($r['resumen_antes'], $r['resumen_despues']);
        $r['usa_indice']     = in_array(lab_nombre_indice($cand['id']), array_column($r['plan_despues'], 'key'), true);
        $r['ok'] = true;
    } catch (PDOException | RuntimeException $ex) {
        // El mensaje de PDO no incluye la contraseña (solo usuario, host o el error de SQL).
        $r['error'] = 'El ensayo se detuvo: ' . $ex->getMessage();
    } finally {
        try {
            $r['copia_eliminada'] = lab_eliminar_copia($pdo, $caso);
        } catch (PDOException $ex) {
            $r['copia_eliminada'] = false;
            $r['error'] = trim(($r['error'] ?? '') . ' No se pudo eliminar la copia: ' . $ex->getMessage());
            $r['ok'] = false;
        }
    }
    return $fin();
}
