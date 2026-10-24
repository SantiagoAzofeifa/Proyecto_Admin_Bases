<?php
// Logica del modulo Monitor (Proyecto 01). La vista vive en monitor.php.
// Todo sale de information_schema y de funciones de MariaDB; ningun dato del
// usuario entra a las consultas (solo DATABASE() de la conexion actual).

// Consultas del monitor. Se muestran tal cual en la pagina para poder
// explicar de donde sale cada dato.
const MONITOR_SQL = [
    'conexion' => "SELECT VERSION() AS version, DATABASE() AS base,
       CURRENT_USER() AS usuario, NOW() AS fecha",

    'tamano_bd' => "SELECT COUNT(*) AS tablas,
       SUM(DATA_LENGTH + INDEX_LENGTH) AS bytes
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'",

    'tablas' => "SELECT TABLE_NAME AS tabla, ENGINE AS motor,
       TABLE_ROWS AS filas_est,
       DATA_LENGTH AS datos, INDEX_LENGTH AS indices,
       DATA_LENGTH + INDEX_LENGTH AS total
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
ORDER BY total DESC, tabla",

    'conteo' => "SELECT COUNT(*) FROM `<tabla>`",

    'analizar' => "ANALYZE TABLE `<tabla>`",
];

// Por encima de este numero de filas estimadas no se hace COUNT(*):
// en tablas grandes es costoso y la estimacion del motor es suficiente.
const MONITOR_LIMITE_CONTEO = 100000;

// Cuantas tablas se resaltan como las que mas espacio consumen.
const MONITOR_TOP = 3;

function monitor_conexion(PDO $pdo): array
{
    return $pdo->query(MONITOR_SQL['conexion'])->fetch();
}

function monitor_tamano_bd(PDO $pdo): array
{
    $r = $pdo->query(MONITOR_SQL['tamano_bd'])->fetch();
    return ['tablas' => (int) $r['tablas'], 'bytes' => (int) $r['bytes']];
}

// Tablas de la base, de mayor a menor tamano, con el conteo exacto cuando
// es barato obtenerlo y el porcentaje que representa cada una del total.
function monitor_tablas(PDO $pdo, int $total): array
{
    $filas = [];
    foreach ($pdo->query(MONITOR_SQL['tablas'])->fetchAll() as $i => $t) {
        $estimadas = (int) $t['filas_est'];
        $filas[] = [
            'tabla'     => $t['tabla'],
            'motor'     => (string) $t['motor'],
            'estimadas' => $estimadas,
            'exactas'   => $estimadas <= MONITOR_LIMITE_CONTEO
                           ? monitor_contar($pdo, $t['tabla']) : null,
            'datos'     => (int) $t['datos'],
            'indices'   => (int) $t['indices'],
            'total'     => (int) $t['total'],
            'pct'       => $total > 0 ? 100 * $t['total'] / $total : 0,
            'top'       => $i < MONITOR_TOP && $t['total'] > 0,
        ];
    }
    return $filas;
}

// El nombre viene de information_schema, no del usuario; aun asi se
// escapa como identificador (` -> ``) por si contiene caracteres raros.
function monitor_ident(string $tabla): string
{
    return '`' . str_replace('`', '``', $tabla) . '`';
}

function monitor_contar(PDO $pdo, string $tabla): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM ' . monitor_ident($tabla))->fetchColumn();
}

// Unica accion del modulo: ANALYZE TABLE sobre las tablas de la propia base
// para que TABLE_ROWS y los tamanos de information_schema se actualicen.
function monitor_analizar(PDO $pdo): array
{
    $tablas = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
    )->fetchAll(PDO::FETCH_COLUMN);

    $errores = [];
    foreach ($tablas as $t) {
        // ANALYZE devuelve filas (Table, Op, Msg_type, Msg_text); hay que leerlas.
        foreach ($pdo->query('ANALYZE TABLE ' . monitor_ident($t))->fetchAll() as $r) {
            if (in_array($r['Msg_type'], ['error', 'Error'], true)) {
                $errores[] = "$t: {$r['Msg_text']}";
            }
        }
    }
    return [count($tablas), $errores];
}

function monitor_tam(int $bytes): string
{
    $unidades = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $v = (float) $bytes;
    while ($v >= 1024 && $i < count($unidades) - 1) {
        $v /= 1024;
        $i++;
    }
    return number_format($v, $i === 0 ? 0 : 1, ',', '.') . ' ' . $unidades[$i];
}

function monitor_num(int $n): string
{
    return number_format($n, 0, ',', '.');
}
