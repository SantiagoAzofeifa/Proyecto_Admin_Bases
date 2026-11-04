<?php
// Catálogo cerrado del Módulo 06 (Laboratorio de índices). Karen Avilés.
// La página recibe SOLO dos identificadores (caso y candidato). Las columnas
// de cada índice candidato salen de esta lista, nunca de lo que escriba el usuario.
// El caso reutiliza el SQL de Q01/Q02 de includes/diag_catalogo.php.

const LAB_MAX_FILAS_DEFECTO = 50000;   // tope de filas a copiar
const LAB_PREFIJO           = 'sbx_';  // prefijo de la copia (igual que CAT_PREFIJO_SBX)

/** Lista cerrada: caso => tabla a copiar + tres candidatos de índice. */
function lab_catalogo(): array
{
    return [
        'Q01' => [
            'tabla' => 'diag_pedidos',
            'candidatos' => [
                'A' => ['nombre' => 'estado + fecha_pedido', 'columnas' => ['estado', 'fecha_pedido']],
                'B' => ['nombre' => 'solo fecha_pedido',     'columnas' => ['fecha_pedido']],
                'C' => ['nombre' => 'solo estado',           'columnas' => ['estado']],
            ],
        ],
        'Q02' => [
            'tabla' => 'diag_pedidos',
            'candidatos' => [
                'A' => ['nombre' => 'id_cliente',          'columnas' => ['id_cliente']],
                'B' => ['nombre' => 'id_cliente + monto',  'columnas' => ['id_cliente', 'monto']],
                'C' => ['nombre' => 'solo monto',          'columnas' => ['monto']],
            ],
        ],
    ];
}

/** Devuelve [caso, candidato] solo si ambos están en la lista cerrada; si no, null. */
function lab_resolver(string $caso, string $cand): ?array
{
    $cat = lab_catalogo();
    if (!isset($cat[$caso]['candidatos'][$cand])) {
        return null;
    }
    return [$cat[$caso] + ['id' => $caso], $cat[$caso]['candidatos'][$cand] + ['id' => $cand]];
}

/** Límite de filas: LAB_MAX_FILAS de dba.conf si existe y es válido; si no, el valor por defecto. */
function lab_max_filas(array $c): int
{
    $v = $c['LAB_MAX_FILAS'] ?? null;
    if ($v !== null && ctype_digit((string) $v) && (int) $v >= 1000 && (int) $v <= LAB_MAX_FILAS_DEFECTO) {
        return (int) $v;     // nunca por encima del tope duro
    }
    return LAB_MAX_FILAS_DEFECTO;
}

/** Nombre de la copia temporal (identificador, hay que escaparlo con cat_ident()). */
function lab_nombre_copia(string $tabla): string
{
    return LAB_PREFIJO . $tabla;
}

function lab_nombre_indice(string $cand): string
{
    return 'idx_sbx_' . strtolower($cand);
}

/** CREATE INDEX sobre la COPIA con las columnas del candidato (identificadores escapados). */
function lab_sql_crear_indice(array $caso, array $cand): string
{
    $cols = implode(', ', array_map('cat_ident', $cand['columnas']));
    return 'CREATE INDEX ' . cat_ident(lab_nombre_indice($cand['id']))
         . ' ON ' . cat_ident(lab_nombre_copia($caso['tabla'])) . ' (' . $cols . ')';
}

/** SQL de la consulta del caso, apuntando a la copia en lugar de la tabla original. */
function lab_sql_consulta(array $caso): ?string
{
    $d = diag_caso($caso['id']);
    if ($d === null) {
        return null;
    }
    return preg_replace('/\b' . preg_quote($caso['tabla'], '/') . '\b/',
                        cat_ident(lab_nombre_copia($caso['tabla'])), $d['sql']);
}

/** Valida el límite: cuenta filas exactas de la tabla original. [bool, filas, motivo] */
function lab_verificar_limite(PDO $pdo, array $c, array $caso): array
{
    if (!cat_tabla_existe($pdo, $caso['tabla'])) {
        return [false, 0, 'La tabla del caso no existe en la base actual.'];
    }
    $n   = (int) $pdo->query('SELECT COUNT(*) FROM ' . cat_ident($caso['tabla']))->fetchColumn();
    $max = lab_max_filas($c);
    return $n > $max
        ? [false, $n, "La tabla tiene $n filas y el límite del laboratorio es $max: no se copia."]
        : [true,  $n, ''];
}

/** Índices secundarios de la COPIA (todos menos PRIMARY): se quitan para partir de un "antes" limpio. */
function lab_indices_a_quitar(PDO $pdo, string $tabla): array
{
    $st = $pdo->prepare(
        "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME <> 'PRIMARY'"
    );
    $st->execute([lab_nombre_copia($tabla)]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}
