<?php
// Control del Módulo 05 (Cartógrafo de relaciones). Parte de Gabriel:
// conteo de huérfanos con límites, bitácora privada y rechazo de entradas no válidas.
//
// Solo LEE la base (SELECT sobre las tablas propias e information_schema).
// Lo único que escribe es la bitácora, un archivo de texto fuera de public_html.
// Los nombres de tablas y columnas salen siempre del catálogo (catalogo_lib.php)
// y se escapan con cat_ident(); nada que escriba el usuario llega al SQL.

// ---------------------------------------------------------------------
// Límites (protegen al servidor compartido; los tres se pueden ajustar)
// ---------------------------------------------------------------------
const CARTO_MAX_FILAS_DEFECTO = 200000;  // filas estimadas máximas (hijo o padre) para contar huérfanos
const CARTO_MAX_RELACIONES    = 60;      // relaciones a las que se les cuentan huérfanos por análisis
const CARTO_SEG_POR_CONSULTA  = 5.0;     // tiempo máximo de cada conteo (max_statement_time)
const CARTO_SEG_TOTAL         = 20.0;    // tiempo máximo de todos los conteos de un análisis
const CARTO_LARGO_NOMBRE      = 64;      // largo máximo de un identificador en MariaDB

/**
 * Límite de filas: CARTO_MAX_FILAS de dba.conf si existe y es válido (entre 1 000 y
 * 1 000 000); si no, el valor por defecto. Mismo criterio que LAB_MAX_FILAS del Módulo 06.
 */
function carto_max_filas(array $c): int
{
    $v = $c['CARTO_MAX_FILAS'] ?? null;
    if ($v !== null && ctype_digit((string) $v) && (int) $v >= 1000 && (int) $v <= 1000000) {
        return (int) $v;
    }
    return CARTO_MAX_FILAS_DEFECTO;
}

/** Límites vigentes de un análisis (los parámetros de carto_analizar() los pueden reemplazar en pruebas). */
function carto_limites(array $c): array
{
    return [
        'filas'      => carto_max_filas($c),
        'relaciones' => CARTO_MAX_RELACIONES,
        'por_consulta' => CARTO_SEG_POR_CONSULTA,
        'total'      => CARTO_SEG_TOTAL,
    ];
}

// ---------------------------------------------------------------------
// Validación de la tabla pedida (?tabla=)
// ---------------------------------------------------------------------

/**
 * ¿La tabla pedida es una tabla propia? Función pura: recibe la lista de cat_tablas().
 * Devuelve [tabla|null, motivo]. Se rechaza todo lo que no sea EXACTAMENTE el nombre
 * de una tabla propia (no se limpia ni se "arregla" la entrada).
 *
 * @param mixed $pedida lo que llegó en $_GET['tabla'] (puede ser un arreglo, un número, etc.)
 */
function carto_validar_tabla(array $propias, $pedida): array
{
    if (!is_string($pedida)) {
        return [null, 'El nombre de tabla recibido no es un texto válido.'];
    }
    if ($pedida === '' || strlen($pedida) > CARTO_LARGO_NOMBRE) {
        return [null, 'El nombre de tabla está vacío o supera ' . CARTO_LARGO_NOMBRE . ' caracteres.'];
    }
    if (!in_array($pedida, $propias, true)) {
        return [null, 'La tabla indicada no existe o no está autorizada: solo se analizan las tablas propias de la base.'];
    }
    return [$pedida, ''];
}

// ---------------------------------------------------------------------
// Conteo de huérfanos con límites
// ---------------------------------------------------------------------

/** Filas estimadas por tabla (TABLE_ROWS de information_schema; es una estimación, no un COUNT). */
function carto_filas_estimadas(PDO $pdo): array
{
    $out = [];
    $st = $pdo->query(
        "SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
    );
    foreach ($st->fetchAll() as $f) {
        $out[$f['TABLE_NAME']] = (int) $f['TABLE_ROWS'];
    }
    return $out;
}

/** Fija max_statement_time en la sesión; devuelve el valor anterior (o null si el motor no lo soporta). */
function carto_fijar_tiempo(PDO $pdo, float $segundos): ?string
{
    try {
        $previo = (string) $pdo->query('SELECT @@SESSION.max_statement_time')->fetchColumn();
        $pdo->exec('SET SESSION max_statement_time = ' . sprintf('%.3F', max(0.001, $segundos)));
        return is_numeric($previo) ? $previo : '0';
    } catch (PDOException $ex) {
        return null;
    }
}

function carto_restaurar_tiempo(PDO $pdo, ?string $previo): void
{
    if ($previo === null) {
        return;
    }
    try {
        $pdo->exec('SET SESSION max_statement_time = ' . sprintf('%.3F', (float) $previo));
    } catch (PDOException $ex) {
        // la sesión termina con la petición; no hay nada más que hacer
    }
}

/** ¿El error es la cancelación por max_statement_time? (código 1969 en MariaDB) */
function carto_es_timeout(PDOException $ex): bool
{
    return ($ex->errorInfo[1] ?? 0) === 1969 || stripos($ex->getMessage(), 'max_statement_time') !== false;
}

/** Resultado "sin dato" de una relación: nunca se inventa un cero. */
function carto_sin_dato(string $nota): array
{
    return ['huerfanos' => null, 'muestra' => [], 'estado' => 'NO DISPONIBLE', 'nota' => $nota];
}

/**
 * Cuenta los huérfanos de UNA relación que ya salió del catálogo (declarada o inferida).
 * Aplica el límite de filas y el tiempo máximo por consulta.
 *
 * @param array $filasEst salida de carto_filas_estimadas()
 * @return array{huerfanos:?int,muestra:array,estado:string,nota:string}
 *         estado: OK (0 huérfanos), ADVERTENCIA (1 o más) o NO DISPONIBLE (omitida o fallida).
 */
function carto_huerfanos_de(PDO $pdo, array $rel, array $filasEst, int $limiteFilas, float $segundos): array
{
    $filas = max($filasEst[$rel['hijo']] ?? 0, $filasEst[$rel['padre']] ?? 0);
    if ($filas > $limiteFilas) {
        return carto_sin_dato('Conteo omitido: la tabla tiene unas ' . number_format($filas, 0, ',', '.')
            . ' filas y el límite es ' . number_format($limiteFilas, 0, ',', '.') . '.');
    }

    $previo = carto_fijar_tiempo($pdo, $segundos);
    try {
        $n = cat_contar_huerfanos($pdo, $rel);
        $muestra = [];
        if ($n > 0) {
            $muestra = $pdo->query(
                cat_sql_muestra_huerfanos($rel['hijo'], $rel['col_hijo'], $rel['padre'], $rel['col_padre'], 5)
            )->fetchAll(PDO::FETCH_COLUMN);
        }
        return ['huerfanos' => $n, 'muestra' => $muestra, 'estado' => $n > 0 ? 'ADVERTENCIA' : 'OK', 'nota' => ''];
    } catch (PDOException $ex) {
        return carto_sin_dato(carto_es_timeout($ex)
            ? 'Conteo cancelado: superó el tiempo máximo de ' . rtrim(rtrim(sprintf('%.1F', $segundos), '0'), '.') . ' s.'
            : 'El motor rechazó la consulta (código ' . ($ex->errorInfo[1] ?? '?') . ').');
    } finally {
        carto_restaurar_tiempo($pdo, $previo);
    }
}

// ---------------------------------------------------------------------
// Bitácora privada (fuera de public_html, permisos 600)
// Formato: inicio | motor | base | tabla | acción | resultado | detalle
// Resultado: OK, ADVERTENCIA (hay huérfanos) o ERROR.
// ---------------------------------------------------------------------

function cartografo_ruta_log(array $c): string
{
    return $c['DIR_LOGS'] . '/cartografo.log';
}

/** Texto apto para una línea de bitácora: sin separadores, saltos de línea ni caracteres de control. */
function carto_limpio($s, int $max = 200): string
{
    $s = preg_replace('/[\x00-\x1F\x7F|]+/', ' ', (string) $s);
    return trim(mb_substr((string) $s, 0, $max));
}

/**
 * Registra un análisis. Es la función que cartografo.php ya invoca si existe.
 * Claves de $r: inicio, base, tabla, relaciones, huerfanos, ok; opcionales:
 * motor, accion, omitidas, duracion, mensaje, detalle.
 * Devuelve false si no se pudo escribir (la pantalla lo avisa).
 */
function cartografo_registrar(array $c, array $r): bool
{
    $ok        = (bool) ($r['ok'] ?? false);
    $huerfanos = (int) ($r['huerfanos'] ?? 0);
    $resultado = !$ok ? 'ERROR' : ($huerfanos > 0 ? 'ADVERTENCIA' : 'OK');

    $detalle = (string) ($r['detalle'] ?? '');
    if ($detalle === '') {
        $partes = [];
        if (!empty($r['mensaje'])) {
            $partes[] = rtrim((string) $r['mensaje'], '. ') . '.';
        }
        if (isset($r['relaciones'])) {
            $partes[] = (int) $r['relaciones'] . ' relaciones analizadas, ' . $huerfanos . ' filas huérfanas.';
        }
        if (!empty($r['omitidas'])) {
            $partes[] = (int) $r['omitidas'] . ' sin conteo (límite o tiempo).';
        }
        if (isset($r['duracion'])) {
            $partes[] = sprintf('Duración: %.3f s.', (float) $r['duracion']);
        }
        $detalle = implode(' ', $partes);
    }

    $linea = implode(' | ', [
        carto_limpio($r['inicio'] ?? date('Y-m-d H:i:s P'), 30),
        carto_limpio($r['motor'] ?? ($c['DB_MOTOR'] ?? 'MariaDB'), 30),
        carto_limpio($r['base'] ?? ($c['DB_NAME'] ?? ''), 64),
        carto_limpio($r['tabla'] ?? '(todas)', 80),
        carto_limpio($r['accion'] ?? 'Análisis de relaciones', 60),
        $resultado,
        carto_limpio($detalle, 300),
    ]) . "\n";

    $dir = dirname(cartografo_ruta_log($c));
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return false;
    }
    $ruta   = cartografo_ruta_log($c);
    $nuevo  = !is_file($ruta);
    $escrito = @file_put_contents($ruta, $linea, FILE_APPEND | LOCK_EX) !== false;
    if ($escrito && $nuevo) {
        @chmod($ruta, 0600);
    }
    return $escrito;
}

/** Líneas de la bitácora, la más reciente primero. */
function cartografo_bitacora(array $c, int $max = 10): array
{
    $ruta = cartografo_ruta_log($c);
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
