<?php
// Lector del catálogo de la base (Módulos 05 y 06). Karen Avilés.
// Solo LEE information_schema de la base actual (DATABASE()); no modifica nada.
// Regla: los NOMBRES de tablas/columnas (identificadores) no se pueden enviar
// como parámetros, así que solo se usan si salieron de este catálogo y se
// escapan con cat_ident(). Los VALORES siempre van por sentencias preparadas.

// Prefijo de las copias temporales del Módulo 06: nunca se listan como tablas propias.
const CAT_PREFIJO_SBX = 'sbx_';

/** Escapa un identificador para MariaDB (duplica el acento grave). */
function cat_ident(string $nombre): string
{
    return '`' . str_replace('`', '``', $nombre) . '`';
}

/** Tablas base propias de la base actual (sin copias sbx_). */
function cat_tablas(PDO $pdo): array
{
    $t = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
         ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_filter($t, fn($n) => strpos($n, CAT_PREFIJO_SBX) !== 0));
}

function cat_tabla_existe(PDO $pdo, string $tabla): bool
{
    return in_array($tabla, cat_tablas($pdo), true);
}

/** Columnas por tabla: [tabla => [[col, tipo, tipo_completo, llave], ...]]. */
function cat_columnas(PDO $pdo): array
{
    $st = $pdo->query(
        "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, COLUMN_KEY
         FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
         ORDER BY TABLE_NAME, ORDINAL_POSITION"
    );
    $out = [];
    foreach ($st->fetchAll() as $f) {
        $out[$f['TABLE_NAME']][] = [
            'col' => $f['COLUMN_NAME'], 'tipo' => strtolower($f['DATA_TYPE']),
            'completo' => $f['COLUMN_TYPE'], 'llave' => $f['COLUMN_KEY'],
        ];
    }
    return $out;
}

/** Llave primaria de una columna: [tabla => [col1, col2...]] */
function cat_llaves_primarias(PDO $pdo): array
{
    $st = $pdo->query(
        "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'PRIMARY'
         ORDER BY TABLE_NAME, ORDINAL_POSITION"
    );
    $out = [];
    foreach ($st->fetchAll() as $f) {
        $out[$f['TABLE_NAME']][] = $f['COLUMN_NAME'];
    }
    return $out;
}

/** Relaciones declaradas (FOREIGN KEY): lista de [hijo, col_hijo, padre, col_padre]. */
function cat_relaciones_declaradas(PDO $pdo): array
{
    $st = $pdo->query(
        "SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
         ORDER BY TABLE_NAME, COLUMN_NAME"
    );
    return array_map(fn($f) => [
        'hijo' => $f['TABLE_NAME'], 'col_hijo' => $f['COLUMN_NAME'],
        'padre' => $f['REFERENCED_TABLE_NAME'], 'col_padre' => $f['REFERENCED_COLUMN_NAME'],
    ], $st->fetchAll());
}

/**
 * Función PURA (se prueba sin base de datos). Infiere relaciones no declaradas:
 * una columna de una tabla cuyo nombre y tipo coinciden con la llave primaria
 * de UNA columna de otra tabla, y que no es la llave primaria de su propia tabla.
 * Es una HEURÍSTICA: solo propone, la confirmación la hace el conteo de huérfanos.
 *
 * @param array $columnas  salida de cat_columnas()
 * @param array $pks       salida de cat_llaves_primarias()
 * @param array $declaradas salida de cat_relaciones_declaradas()
 */
function cat_inferir(array $columnas, array $pks, array $declaradas): array
{
    $yaDeclarada = [];
    foreach ($declaradas as $d) {
        $yaDeclarada[$d['hijo'] . '.' . $d['col_hijo']] = true;
    }
    $tipoDe = function (string $t, string $c) use ($columnas) {
        foreach ($columnas[$t] ?? [] as $x) {
            if ($x['col'] === $c) {
                return $x['tipo'];
            }
        }
        return null;
    };
    $res = [];
    foreach ($columnas as $hijo => $cols) {
        foreach ($cols as $c) {
            if (isset($yaDeclarada[$hijo . '.' . $c['col']])) {
                continue;
            }
            foreach ($pks as $padre => $pk) {
                if ($padre === $hijo || count($pk) !== 1 || $pk[0] !== $c['col']) {
                    continue;
                }
                if (in_array($c['col'], $pks[$hijo] ?? [], true)) {
                    continue;   // es parte de su propia llave primaria
                }
                if ($tipoDe($padre, $pk[0]) !== $c['tipo']) {
                    continue;
                }
                $res[] = ['hijo' => $hijo, 'col_hijo' => $c['col'],
                          'padre' => $padre, 'col_padre' => $pk[0]];
            }
        }
    }
    return $res;
}

/** SQL del conteo de huérfanos. Los 4 identificadores ya deben estar validados. */
function cat_sql_huerfanos(string $hijo, string $colH, string $padre, string $colP): string
{
    return 'SELECT COUNT(*) FROM ' . cat_ident($hijo) . ' h'
         . ' LEFT JOIN ' . cat_ident($padre) . ' p ON p.' . cat_ident($colP) . ' = h.' . cat_ident($colH)
         . ' WHERE h.' . cat_ident($colH) . ' IS NOT NULL AND p.' . cat_ident($colP) . ' IS NULL';
}

/** SQL para mostrar unos pocos huérfanos (solo la columna de relación y la llave del hijo). */
function cat_sql_muestra_huerfanos(string $hijo, string $colH, string $padre, string $colP, int $n = 5): string
{
    return 'SELECT h.' . cat_ident($colH) . ' FROM ' . cat_ident($hijo) . ' h'
         . ' LEFT JOIN ' . cat_ident($padre) . ' p ON p.' . cat_ident($colP) . ' = h.' . cat_ident($colH)
         . ' WHERE h.' . cat_ident($colH) . ' IS NOT NULL AND p.' . cat_ident($colP) . ' IS NULL'
         . ' LIMIT ' . max(1, min(20, $n));
}

/**
 * Valida que la relación pedida exista en el catálogo (declarada o inferida)
 * y devuelve la relación canónica, o null. Nada de lo recibido llega a SQL
 * si no coincide exactamente con lo que lee information_schema.
 */
function cat_buscar_relacion(PDO $pdo, string $hijo, string $colH, string $padre, string $colP): ?array
{
    $decl = cat_relaciones_declaradas($pdo);
    $todas = array_merge(
        array_map(fn($r) => $r + ['origen' => 'declarada'], $decl),
        array_map(fn($r) => $r + ['origen' => 'inferida'],
                  cat_inferir(cat_columnas($pdo), cat_llaves_primarias($pdo), $decl))
    );
    foreach ($todas as $r) {
        if ($r['hijo'] === $hijo && $r['col_hijo'] === $colH
            && $r['padre'] === $padre && $r['col_padre'] === $colP
            && strpos($hijo, CAT_PREFIJO_SBX) !== 0) {
            return $r;
        }
    }
    return null;
}

/** Cuenta huérfanos de una relación ya validada con cat_buscar_relacion(). */
function cat_contar_huerfanos(PDO $pdo, array $rel): int
{
    return (int) $pdo->query(
        cat_sql_huerfanos($rel['hijo'], $rel['col_hijo'], $rel['padre'], $rel['col_padre'])
    )->fetchColumn();
}
