<?php
// Prueba por terminal de la parte de Gabriel en el Módulo 05 (Cartógrafo de relaciones):
//   includes/cartografo_control.php  (límites, validación de tabla, conteo de huérfanos, bitácora)
//
// Uso, desde la carpeta de la app (la que contiene config.php):
//   php grupo3_novacloud/tools/probar_gabriel_05.php                  # solo lectura
//   php grupo3_novacloud/tools/probar_gabriel_05.php --con-huerfano   # inserta UN pedido huérfano, lo prueba y lo borra
//
// Las secciones 1 y 2 no tocan la base. Las demás solo hacen SELECT, salvo --con-huerfano,
// que inserta una fila en diag_pedidos (estado 'PRUEBA', id_cliente 999999) y la elimina
// siempre al terminar, aunque la prueba falle. La bitácora de prueba se escribe en una
// carpeta temporal: NO ensucia ~/dba_logs.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$raiz = is_file(__DIR__ . '/../../config.php') ? __DIR__ . '/../..' : __DIR__ . '/..';
require $raiz . '/config.php';
require $raiz . '/includes/monitor_lib.php';
require $raiz . '/includes/catalogo_lib.php';
require $raiz . '/includes/cartografo_lib.php';

$fallos = 0;
function verificar(string $que, bool $ok): void
{
    global $fallos;
    echo ($ok ? '  [OK]    ' : '  [FALLO] ') . $que . "\n";
    $fallos += $ok ? 0 : 1;
}

// ---------------------------------------------------------------------
echo "== 1. Validación de la tabla pedida (sin base) ==\n";
$propias = ['categorias', 'clientes', 'diag_pedidos', 'ventas'];
[$t, $m] = carto_validar_tabla($propias, 'clientes');
verificar('una tabla propia se acepta', $t === 'clientes' && $m === '');
foreach ([
    'tabla inexistente'              => 'no_existe',
    'copia sbx_ del laboratorio'     => 'sbx_diag_pedidos',
    'tabla de otro esquema'          => 'mysql.user',
    'mayúsculas distintas'           => 'CLIENTES',
    'con espacio al final'           => 'clientes ',
    'inyección SQL'                  => "clientes`; DROP TABLE clientes; -- ",
    'texto vacío'                    => '',
    'nombre de 65 caracteres'        => str_repeat('a', 65),
    'arreglo (?tabla[]=x)'           => ['clientes'],
    'número'                         => 5,
    'null'                           => null,
] as $caso => $entrada) {
    [$t, $m] = carto_validar_tabla($propias, $entrada);
    verificar("se rechaza: $caso", $t === null && $m !== '');
}

// ---------------------------------------------------------------------
echo "\n== 2. Bitácora (carpeta temporal, sin base) ==\n";
$tmp = sys_get_temp_dir() . '/carto_prueba_' . getmypid();
$cfgPrueba = ['DIR_LOGS' => $tmp . '/dba_logs', 'DB_MOTOR' => 'MariaDB', 'DB_NAME' => 'prueba'];
$ruta = cartografo_ruta_log($cfgPrueba);
verificar('la carpeta de logs se crea sola si falta', cartografo_registrar($cfgPrueba, [
    'inicio' => '2026-01-01 10:00:00 -06:00', 'base' => 'prueba', 'tabla' => '(todas)',
    'relaciones' => 5, 'huerfanos' => 0, 'ok' => true, 'duracion' => 0.12]));
cartografo_registrar($cfgPrueba, ['inicio' => '2026-01-01 10:01:00 -06:00', 'base' => 'prueba',
    'relaciones' => 5, 'huerfanos' => 1, 'ok' => true]);
cartografo_registrar($cfgPrueba, ['inicio' => '2026-01-01 10:02:00 -06:00', 'base' => 'prueba',
    'accion' => 'Solicitud rechazada', 'ok' => false, 'mensaje' => 'La tabla no existe',
    'tabla' => "(no válida) x | OK\n2026-01-01 | falso | falso | falso | falso | OK | linea inyectada"]);
$log = cartografo_bitacora($cfgPrueba, 10);
verificar('se guardaron 3 líneas (la entrada con salto de línea no creó una cuarta)', count(file($ruta)) === 3 && count($log) === 3);
verificar('0 huérfanos -> OK', $log[2]['resultado'] === 'OK');
verificar('1 huérfano -> ADVERTENCIA', $log[1]['resultado'] === 'ADVERTENCIA');
verificar('ok=false -> ERROR', $log[0]['resultado'] === 'ERROR');
verificar('la línea es de 7 campos aunque la entrada traía "|"', count(explode(' | ', trim(file($ruta)[2]))) === 7);
verificar('la más reciente sale primero', $log[0]['fecha'] > $log[2]['fecha']);
verificar('el archivo queda con permisos 600', (fileperms($ruta) & 0777) === 0600);
verificar('carto_max_filas: valor válido de dba.conf', carto_max_filas(['CARTO_MAX_FILAS' => '5000']) === 5000);
verificar('carto_max_filas: valor inválido -> por defecto', carto_max_filas(['CARTO_MAX_FILAS' => 'abc']) === CARTO_MAX_FILAS_DEFECTO
    && carto_max_filas(['CARTO_MAX_FILAS' => '5']) === CARTO_MAX_FILAS_DEFECTO && carto_max_filas([]) === CARTO_MAX_FILAS_DEFECTO);
$rutaMala = ['DIR_LOGS' => '/proc/no_se_puede_escribir/dba_logs'];
verificar('si no se puede escribir, devuelve false (la pantalla lo avisa)', cartografo_registrar($rutaMala, ['ok' => true]) === false);

// ---------------------------------------------------------------------
$pdo = conectar_bd();
$c   = cargar_config();
$con = monitor_conexion($pdo);
echo "\n== 3. Conteo de huérfanos contra el LEFT JOIN manual ==\n";
echo "  Servidor {$con['version']} · base {$con['base']} · usuario {$con['usuario']}\n";
$lim = carto_limites($c);
echo "  Límites: {$lim['filas']} filas, {$lim['relaciones']} relaciones, {$lim['por_consulta']} s por consulta, {$lim['total']} s en total\n";

function manual(PDO $pdo, array $r): int
{
    // Escrito aparte de cat_sql_huerfanos(): sirve de contraste.
    $sql = sprintf('SELECT COUNT(*) FROM `%s` h LEFT JOIN `%s` p ON h.`%s` = p.`%s` WHERE h.`%s` IS NOT NULL AND p.`%s` IS NULL',
        $r['hijo'], $r['padre'], $r['col_hijo'], $r['col_padre'], $r['col_hijo'], $r['col_padre']);
    return (int) $pdo->query($sql)->fetchColumn();
}

$mapa = carto_analizar($pdo, null);
verificar('hay al menos una relación', count($mapa['relaciones']) > 0);
foreach ($mapa['relaciones'] as $r) {
    $m = manual($pdo, $r);
    $txt = sprintf('%s.%s -> %s.%s [%s] huérfanos=%s estado=%s (manual=%d)', $r['hijo'], $r['col_hijo'],
        $r['padre'], $r['col_padre'], $r['origen'], $r['huerfanos'] ?? 'n/d', $r['estado'], $m);
    verificar($txt, $r['huerfanos'] === $m && $r['estado'] === ($m > 0 ? 'ADVERTENCIA' : 'OK'));
}
$sesionAntes = (string) $pdo->query('SELECT @@SESSION.max_statement_time')->fetchColumn();
verificar("max_statement_time de la sesión quedó restaurado ($sesionAntes)", (float) $sesionAntes === 0.0);

// ---------------------------------------------------------------------
echo "\n== 4. Límites ==\n";
$total = count($mapa['relaciones']);
$a = carto_analizar($pdo, null, ['filas' => 1, 'relaciones' => 60, 'por_consulta' => 5.0, 'total' => 20.0]);
verificar('límite de filas = 1: ninguna relación se cuenta, todas "No disponible" (no se inventa un cero)',
    count($a['relaciones']) === $total && $a['omitidas'] === $total
    && array_unique(array_column($a['relaciones'], 'huerfanos')) === [null]
    && array_unique(array_column($a['relaciones'], 'estado')) === ['NO DISPONIBLE']);
$b = carto_analizar($pdo, null, ['filas' => 200000, 'relaciones' => 2, 'por_consulta' => 5.0, 'total' => 20.0]);
verificar('límite de relaciones = 2: se cuentan 2 y el resto queda omitido',
    $total > 2 && $b['omitidas'] === $total - 2 && count(array_filter($b['relaciones'], fn($r) => $r['huerfanos'] !== null)) === 2);
$d = carto_analizar($pdo, null, ['filas' => 200000, 'relaciones' => 60, 'por_consulta' => 0.001, 'total' => 20.0]);
$cancel = array_filter($d['relaciones'], fn($r) => $r['huerfanos'] === null && strpos($r['motivo'], 'cancelado') !== false);
echo "  (con 0,001 s por consulta se cancelaron " . count($cancel) . " de $total relaciones)\n";
verificar('tiempo máximo por consulta: la consulta lenta se cancela con aviso (no hay error fatal)', $d['omitidas'] === count($cancel) && count($cancel) >= 1);
verificar('después de cancelar, max_statement_time vuelve al valor anterior',
    (float) $pdo->query('SELECT @@SESSION.max_statement_time')->fetchColumn() === (float) $sesionAntes);
$e = carto_analizar($pdo, null, ['filas' => 200000, 'relaciones' => 60, 'por_consulta' => 5.0, 'total' => -1.0]);
verificar('tiempo total agotado: las relaciones se omiten con aviso', $e['omitidas'] === $total);
$f = carto_analizar($pdo, 'clientes');
verificar('con tabla en foco solo entran relaciones de esa tabla',
    count($f['relaciones']) > 0 && count(array_filter($f['relaciones'], fn($r) => $r['hijo'] !== 'clientes' && $r['padre'] !== 'clientes')) === 0);

// ---------------------------------------------------------------------
echo "\n== 5. Errores controlados ==\n";
$propiasReales = cat_tablas($pdo);
foreach (['tabla_que_no_existe', 'sbx_diag_pedidos', "x`; DROP TABLE clientes; --"] as $mala) {
    [$t, $m] = carto_validar_tabla($propiasReales, $mala);
    cartografo_registrar($cfgPrueba, ['inicio' => date('Y-m-d H:i:s P'), 'base' => $con['base'], 'accion' => 'Solicitud rechazada',
        'tabla' => '(no válida) ' . $mala, 'ok' => false, 'mensaje' => $m]);
    verificar('se rechaza «' . substr($mala, 0, 25) . '» y se registra ERROR', $t === null && cartografo_bitacora($cfgPrueba, 1)[0]['resultado'] === 'ERROR');
}
$existen = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes'")->fetchColumn();
verificar('la tabla clientes sigue existiendo después de los intentos de inyección', $existen === 1);

// ---------------------------------------------------------------------
if (in_array('--con-huerfano', $argv, true)) {
    echo "\n== 6. Fila huérfana real (se inserta y se borra) ==\n";
    $antes = manual($pdo, ['hijo' => 'diag_pedidos', 'col_hijo' => 'id_cliente', 'padre' => 'clientes', 'col_padre' => 'id_cliente']);
    verificar('antes de insertar hay 0 huérfanos', $antes === 0);
    try {
        $pdo->exec("INSERT INTO diag_pedidos (id_cliente, estado, monto, fecha_pedido) VALUES (999999, 'PRUEBA', 1.00, '2026-01-01')");
        $mapa = carto_analizar($pdo, null);
        $rel = null;
        foreach ($mapa['relaciones'] as $r) {
            if ($r['hijo'] === 'diag_pedidos' && $r['col_hijo'] === 'id_cliente') { $rel = $r; }
        }
        verificar('el módulo encuentra la relación diag_pedidos.id_cliente -> clientes', $rel !== null && $rel['padre'] === 'clientes');
        verificar('marca ADVERTENCIA con 1 huérfano', $rel && $rel['estado'] === 'ADVERTENCIA' && $rel['huerfanos'] === 1);
        verificar('coincide con el LEFT JOIN manual', $rel && manual($pdo, $rel) === 1);
        verificar('la muestra trae el valor 999999', $rel && in_array(999999, array_map('intval', $rel['muestra']), true));
        verificar('el total de huérfanos del mapa es 1', $mapa['total_huerfanos'] === 1);
        cartografo_registrar($cfgPrueba, ['inicio' => date('Y-m-d H:i:s P'), 'base' => $con['base'], 'tabla' => '(todas)',
            'relaciones' => count($mapa['relaciones']), 'huerfanos' => $mapa['total_huerfanos'], 'ok' => true]);
        verificar('la bitácora lo registra como ADVERTENCIA', cartografo_bitacora($cfgPrueba, 1)[0]['resultado'] === 'ADVERTENCIA');
    } finally {
        $pdo->exec("DELETE FROM diag_pedidos WHERE estado = 'PRUEBA' AND id_cliente = 999999");
    }
    $quedan = (int) $pdo->query("SELECT COUNT(*) FROM diag_pedidos WHERE estado = 'PRUEBA'")->fetchColumn();
    verificar('la fila de prueba se eliminó', $quedan === 0);
    $mapa = carto_analizar($pdo, null);
    verificar('después de borrarla vuelve a 0 huérfanos', $mapa['total_huerfanos'] === 0);
} else {
    echo "\n(Sección 6 omitida: use --con-huerfano para insertar y borrar un pedido huérfano de prueba.)\n";
}

// Limpieza de la bitácora temporal
@unlink($ruta);
@rmdir($tmp . '/dba_logs');
@rmdir($tmp);

echo "\n" . ($fallos === 0 ? 'Todo correcto.' : "Fallos: $fallos") . "\n";
exit($fallos === 0 ? 0 : 1);
