<?php
// Logica del modulo Diagnostico (Modulo 03). La vista vive en diagnostico.php.
// Solo ejecuta el SQL del catalogo cerrado (diag_catalogo.php): la pagina
// recibe un identificador (Q01/Q02) y nunca SQL ni valores del usuario.
// Reutiliza monitor_conexion(), monitor_ident() y monitor_num() del Modulo 01.

// Descripcion de cada valor de la columna "type" de EXPLAIN en MariaDB.
const DIAG_TIPOS = [
    'system'          => 'tabla de una sola fila; se lee una vez',
    'const'           => 'a lo sumo una fila, ubicada por llave primaria o única con un valor constante',
    'eq_ref'          => 'una sola fila por cada fila de la tabla anterior, por llave primaria o única; es el acceso típico de un JOIN bien indexado',
    'ref'             => 'búsqueda por igualdad en un índice no único',
    'ref_or_null'     => 'búsqueda por igualdad en un índice, incluyendo valores NULL',
    'fulltext'        => 'búsqueda con índice FULLTEXT',
    'index_merge'     => 'combina varios índices de la misma tabla',
    'range'           => 'recorre solo un rango del índice',
    'index'           => 'recorre el índice completo, sin leer la tabla por cada fila',
    'ALL'             => 'recorrido completo de la tabla, full table scan: lee todas las filas',
];

// Frases de la columna Extra que vale la pena explicar.
const DIAG_EXTRA = [
    'Using index condition' => 'parte de la condición se evalúa dentro del índice antes de leer la fila completa',
    'Using where'           => 'el motor filtra las filas leídas con la condición WHERE',
    'Using index'           => 'índice de cobertura: todo lo necesario está en el índice y no se lee la tabla',
    'Using filesort'        => 'hace falta un ordenamiento adicional porque el acceso no entrega el orden pedido',
    'Using temporary'       => 'usa una tabla temporal intermedia',
    'Using join buffer'     => 'JOIN sin índice aprovechable: usa un búfer en memoria para combinar filas',
    'Using MRR'             => 'lectura por rangos múltiples (Multi-Range Read) para leer las filas en orden de disco',
];

/** EXPLAIN tabular del SQL del catalogo. */
function diag_explain(PDO $pdo, string $sql): array
{
    return $pdo->query('EXPLAIN ' . $sql)->fetchAll();
}

/** EXPLAIN FORMAT=JSON, devuelto con sangria para leerlo. */
function diag_explain_json(PDO $pdo, string $sql): string
{
    $json = (string) $pdo->query('EXPLAIN FORMAT=JSON ' . $sql)->fetchColumn();
    $dec  = json_decode($json);
    return $dec === null ? $json : json_encode($dec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Resultado de la consulta resumen (sirve para comparar antes/despues). */
function diag_resumen(PDO $pdo, string $sql): array
{
    return $pdo->query($sql)->fetch() ?: [];
}

/** Primeras filas del resultado real, solo como muestra. */
function diag_muestra(PDO $pdo, string $sql, int $n = 5): array
{
    $st = $pdo->query($sql);
    $filas = [];
    while (count($filas) < $n && ($f = $st->fetch())) {
        $filas[] = $f;
    }
    $st->closeCursor();
    return $filas;
}

/**
 * Tablas del caso. El catalogo las nombra ("diag_pedidos, clientes"); aun asi
 * solo se aceptan las que existen en la base actual segun information_schema.
 */
function diag_tablas(PDO $pdo, array $caso): array
{
    $pedidas = array_map('trim', explode(',', $caso['tabla']));
    $reales  = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
    )->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_intersect($pedidas, $reales));
}

/** Indices definidos en las tablas del caso, una fila por indice. */
function diag_indices(PDO $pdo, array $tablas): array
{
    if (!$tablas) {
        return [];
    }
    $marcas = implode(',', array_fill(0, count($tablas), '?'));
    $st = $pdo->prepare(
        "SELECT TABLE_NAME AS tabla, INDEX_NAME AS indice, NON_UNIQUE AS no_unico,
                GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ', ') AS columnas,
                MAX(CARDINALITY) AS cardinalidad
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($marcas)
         GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE
         ORDER BY TABLE_NAME, INDEX_NAME = 'PRIMARY' DESC, INDEX_NAME"
    );
    $st->execute($tablas);
    return $st->fetchAll();
}

/** Interpretacion en texto de cada fila del plan. */
function diag_interpretar(array $plan): array
{
    $notas = [];
    foreach ($plan as $f) {
        $tipo  = (string) ($f['type'] ?? '');
        $clave = $f['key'] ?? null;
        $pos   = $f['possible_keys'] ?? null;
        $txt   = "Tabla {$f['table']}: acceso {$tipo}";
        $txt  .= isset(DIAG_TIPOS[$tipo]) ? ' (' . DIAG_TIPOS[$tipo] . ').' : '.';

        if ($clave) {
            $txt .= " Usa el índice {$clave}";
            $txt .= !empty($f['ref']) ? " comparando con {$f['ref']}." : '.';
        } elseif ($pos) {
            $txt .= " Había índices posibles ({$pos}) pero el optimizador no usó ninguno.";
        } else {
            $txt .= ' No hay índice aplicable a la condición: no usa índice.';
        }

        $filas = (int) ($f['rows'] ?? 0);
        $txt .= ' Estima examinar ' . monitor_num($filas) . ($filas === 1 ? ' fila' : ' filas');
        $txt .= isset($f['filtered']) ? ', de las que pasaría el ' . $f['filtered'] . ' %.' : '.';

        foreach (diag_extras((string) ($f['Extra'] ?? '')) as $frase => $explicacion) {
            $txt .= " «{$frase}»: {$explicacion}.";
        }
        $notas[] = $txt;
    }
    return $notas;
}

/** Frases conocidas presentes en la columna Extra. */
function diag_extras(string $extra): array
{
    $hay = [];
    foreach (DIAG_EXTRA as $frase => $explicacion) {
        // "Using index" tambien es prefijo de "Using index condition".
        if ($frase === 'Using index' && !preg_match('/Using index(?! condition)/', $extra)) {
            continue;
        }
        if (stripos($extra, $frase) !== false) {
            $hay[$frase] = $explicacion;
        }
    }
    return $hay;
}

/**
 * Diferencias por tabla entre el plan de referencia y el actual.
 * Compara solo lo que reporta MariaDB: tipo de acceso, indice, filas
 * estimadas y el ordenamiento adicional (filesort). Las filas son
 * estimaciones del optimizador, no tiempo.
 */
function diag_comparar(array $antes, array $ahora): array
{
    $porTabla = fn(array $plan) => array_column($plan, null, 'table');
    $a = $porTabla($antes);
    $d = $porTabla($ahora);
    $notas = [];
    foreach (array_unique(array_merge(array_keys($a), array_keys($d))) as $t) {
        if (!isset($a[$t]) || !isset($d[$t])) {
            $notas[] = "Tabla {$t}: aparece solo en el plan " . (isset($a[$t]) ? 'anterior.' : 'actual.');
            continue;
        }
        $x = $a[$t];
        $y = $d[$t];
        $cambios = [];
        if ($x['type'] !== $y['type']) {
            $cambios[] = "el acceso pasó de {$x['type']} a {$y['type']}";
        }
        if (($x['key'] ?? null) !== ($y['key'] ?? null)) {
            $cambios[] = 'el índice pasó de ' . ($x['key'] ?: 'ninguno') . ' a ' . ($y['key'] ?: 'ninguno');
        }
        if ((int) $x['rows'] !== (int) $y['rows']) {
            $cambios[] = 'las filas estimadas pasaron de ' . monitor_num((int) $x['rows'])
                       . ' a ' . monitor_num((int) $y['rows']);
        }
        $fsA = stripos((string) $x['Extra'], 'filesort') !== false;
        $fsD = stripos((string) $y['Extra'], 'filesort') !== false;
        if ($fsA !== $fsD) {
            $cambios[] = $fsA ? 'ya no necesita ordenamiento adicional (desapareció Using filesort)'
                              : 'ahora necesita ordenamiento adicional (Using filesort)';
        }
        $notas[] = "Tabla {$t}: " . ($cambios ? implode('; ', $cambios) . '.' : 'sin cambios en el plan.');
    }
    return $notas;
}

// ---------------------------------------------------------------------
// Plan de referencia ("antes"), guardado fuera de public_html.
// El identificador ya fue validado contra el catalogo, por eso puede
// formar parte del nombre del archivo.
// ---------------------------------------------------------------------

function diag_ref_ruta(array $c, string $id): string
{
    return $c['DIR_PLANES'] . '/' . $id . '_antes.json';
}

function diag_ref_leer(array $c, string $id): ?array
{
    $ruta = diag_ref_ruta($c, $id);
    if (!is_readable($ruta)) {
        return null;
    }
    $datos = json_decode((string) file_get_contents($ruta), true);
    return is_array($datos) ? $datos : null;
}

function diag_ref_guardar(array $c, string $id, array $datos): bool
{
    if (!is_dir($c['DIR_PLANES']) && !mkdir($c['DIR_PLANES'], 0700, true)) {
        return false;
    }
    $ruta = diag_ref_ruta($c, $id);
    $tmp  = $ruta . '.tmp';
    $json = json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    chmod($tmp, 0600);
    return rename($tmp, $ruta);
}

function diag_ref_borrar(array $c, string $id): bool
{
    $ruta = diag_ref_ruta($c, $id);
    return !is_file($ruta) || unlink($ruta);
}

/** Mismos valores en ambos resumenes (se comparan como texto). */
function diag_resumen_igual(array $a, array $b): bool
{
    return array_map('strval', $a) === array_map('strval', $b);
}
