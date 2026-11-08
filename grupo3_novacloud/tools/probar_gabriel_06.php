<?php
// Prueba por terminal de la parte de Gabriel en el Módulo 06 (Laboratorio de índices):
//   includes/lab_control.php  (bloqueo, respaldo previo, limpieza garantizada y bitácora)
//
// Uso, desde la carpeta de la app (la que contiene config.php):
//   php grupo3_novacloud/tools/probar_gabriel_06.php
//
// ATENCIÓN: esta prueba SÍ hace DDL en la base propia (crea y borra sbx_diag_pedidos) y genera
// UN respaldo real con backup.sh (queda en ~/respaldos). diag_pedidos NO se modifica.
// La bitácora y el bloqueo de la prueba van a una carpeta temporal, no a ~/dba_logs.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$raiz = is_file(__DIR__ . '/../../config.php') ? __DIR__ . '/../..' : __DIR__ . '/..';
require $raiz . '/config.php';
require $raiz . '/includes/monitor_lib.php';
require $raiz . '/includes/catalogo_lib.php';
require $raiz . '/includes/diag_catalogo.php';
require $raiz . '/includes/diag_lib.php';
require $raiz . '/includes/lab_catalogo.php';
require $raiz . '/includes/lab_ensayo.php';
require $raiz . '/includes/respaldos_lib.php';
require $raiz . '/includes/lab_control.php';

$fallos = 0;
function verificar(string $que, bool $ok): void
{
    global $fallos;
    echo ($ok ? '  [OK]    ' : '  [FALLO] ') . $que . "\n";
    $fallos += $ok ? 0 : 1;
}
function copias(PDO $pdo): array
{
    return $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'sbx\\_%'")->fetchAll(PDO::FETCH_COLUMN);
}

$pdo = conectar_bd();
$c   = cargar_config();
$tmp = sys_get_temp_dir() . '/lab_prueba_' . getmypid();
mkdir($tmp . '/scripts', 0700, true);
// Respaldo "de mentira" para las pruebas que no necesitan uno real.
file_put_contents($tmp . '/scripts/backup.sh', "#!/bin/bash\necho respaldo_simulado.sql.gz\n");
chmod($tmp . '/scripts/backup.sh', 0700);

$cfg = $c;                                  // respaldo REAL, bitácora y bloqueo temporales
$cfg['DIR_LOGS'] = $tmp . '/dba_logs';
$cfgSim = $cfg;                             // respaldo simulado
$cfgSim['DIR_SCRIPTS'] = $tmp . '/scripts';

$filasAntes = (int) $pdo->query('SELECT COUNT(*) FROM diag_pedidos')->fetchColumn();
$idxAntes   = diag_indices($pdo, ['diag_pedidos']);
verificar('punto de partida: no hay copias sbx_ en la base', copias($pdo) === []);

// ---------------------------------------------------------------------
echo "\n== 1. Rechazos: no se bloquea, no se respalda, no se toca la base ==\n";
foreach (['caso inexistente' => ['Q99', 'A'], 'candidato Z' => ['Q01', 'Z'],
          'inyección en el caso' => ["Q01'; DROP TABLE diag_pedidos; --", 'A'], 'candidato vacío' => ['Q01', '']] as $nombre => [$caso, $cand]) {
    $r = lab_solicitar($pdo, $cfgSim, $caso, $cand);
    verificar("$nombre: ERROR, sin respaldo ni copia", !$r['ok'] && $r['error'] !== null && $r['respaldo'] === null && $r['copia_eliminada'] === null && copias($pdo) === []);
}
$cfgLim = $cfgSim;
$cfgLim['LAB_MAX_FILAS'] = '1000';
$r = lab_solicitar($pdo, $cfgLim, 'Q01', 'A');
verificar("tabla sobre el límite de filas: ERROR «{$r['error']}»", !$r['ok'] && $r['respaldo'] === null && strpos((string) $r['error'], 'límite') !== false);

// ---------------------------------------------------------------------
echo "\n== 2. Bloqueo: un solo ensayo a la vez ==\n";
$lock = lab_bloquear($cfgSim);
verificar('se toma el bloqueo', $lock !== false);
$r = lab_solicitar($pdo, $cfgSim, 'Q01', 'A');
verificar('con el bloqueo ocupado, el ensayo se rechaza con ERROR y sin respaldo', !$r['ok'] && $r['respaldo'] === null && strpos((string) $r['error'], 'otro ensayo') !== false);
verificar('el segundo intento de bloqueo también falla', lab_bloquear($cfgSim) === false);
lab_desbloquear($lock);
$otra = lab_bloquear($cfgSim);
verificar('al soltarlo, se puede volver a tomar', $otra !== false);
lab_desbloquear($otra);

// ---------------------------------------------------------------------
echo "\n== 3. Si el respaldo previo falla, no se crea la copia ==\n";
$cfgMalo = $cfgSim;
$cfgMalo['DIR_SCRIPTS'] = $tmp . '/no_existe';
$r = lab_solicitar($pdo, $cfgMalo, 'Q01', 'A');
verificar("ERROR «{$r['error']}»", !$r['ok'] && strpos((string) $r['error'], 'respaldo previo') !== false);
verificar('no se creó ninguna copia', copias($pdo) === [] && $r['copia_eliminada'] === null);
verificar('el bloqueo quedó libre', ($l = lab_bloquear($cfgSim)) !== false);
lab_desbloquear($l ?? null);

// ---------------------------------------------------------------------
echo "\n== 4. Ensayo completo con respaldo REAL y un resto de ensayo anterior ==\n";
$pdo->exec('DROP TABLE IF EXISTS sbx_diag_pedidos');
$pdo->exec('CREATE TABLE sbx_diag_pedidos LIKE diag_pedidos');      // simula un ensayo que murió a medias
verificar('se dejó un resto sbx_diag_pedidos', copias($pdo) === ['sbx_diag_pedidos']);
$r = lab_solicitar($pdo, $cfg, 'Q01', 'A');
echo '  Respaldo: ' . ($r['respaldo'] ?? '(ninguno)') . ' · duración ' . $r['duracion_total_ms'] . " ms\n";
verificar('el ensayo termina OK' . ($r['ok'] ? '' : ': ' . $r['error']), $r['ok']);
verificar('se generó el respaldo previo (.sql.gz)', !empty($r['respaldo']) && substr($r['respaldo'], -7) === '.sql.gz');
verificar('el resto del ensayo anterior se eliminó antes de empezar', $r['restos_borrados'] === ['sbx_diag_pedidos']);
verificar('la copia quedó eliminada y confirmada', $r['copia_eliminada'] === true && copias($pdo) === []);
verificar('el optimizador usó el índice candidato', !empty($r['usa_indice']));
verificar('diag_pedidos conserva sus filas', (int) $pdo->query('SELECT COUNT(*) FROM diag_pedidos')->fetchColumn() === $filasAntes);
verificar('diag_pedidos conserva sus índices', diag_indices($pdo, ['diag_pedidos']) == $idxAntes);
verificar('la bitácora se escribió', $r['bitacora_ok'] === true);
verificar('el bloqueo quedó libre', ($l = lab_bloquear($cfg)) !== false);
lab_desbloquear($l ?? null);

// ---------------------------------------------------------------------
echo "\n== 5. Limpieza garantizada: excepción con la copia ya creada ==\n";
$r = lab_solicitar($pdo, $cfgSim, 'Q01', 'A', function (PDO $pdo, array $caso) {
    $pdo->exec('CREATE TABLE ' . cat_ident(lab_nombre_copia($caso['tabla'])) . ' LIKE ' . cat_ident($caso['tabla']));
    throw new RuntimeException('fallo simulado a mitad del ensayo');
});
verificar("ERROR «{$r['error']}»", !$r['ok'] && strpos((string) $r['error'], 'fallo simulado') !== false);
verificar('la copia se eliminó de todos modos', $r['copia_eliminada'] === true && copias($pdo) === []);
verificar('el bloqueo quedó libre', ($l = lab_bloquear($cfgSim)) !== false);
lab_desbloquear($l ?? null);

// ---------------------------------------------------------------------
echo "\n== 6. Limpieza garantizada: el proceso PHP muere (exit) con la copia creada ==\n";
$hijo = $tmp . '/hijo.php';
file_put_contents($hijo, '<?php
$raiz = $argv[1]; $cfgJson = json_decode($argv[2], true);
foreach (["config","includes/monitor_lib","includes/catalogo_lib","includes/diag_catalogo","includes/diag_lib","includes/lab_catalogo","includes/lab_ensayo","includes/respaldos_lib","includes/lab_control"] as $f) require "$raiz/$f.php";
$pdo = conectar_bd();
lab_solicitar($pdo, array_merge(cargar_config(), $cfgJson), "Q01", "A", function (PDO $pdo, array $caso) {
    $pdo->exec("CREATE TABLE " . cat_ident(lab_nombre_copia($caso["tabla"])) . " LIKE " . cat_ident($caso["tabla"]));
    exit(3);   // el proceso termina aquí: no corren los finally, solo el shutdown handler
});
');
$salida = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($hijo) . ' ' . escapeshellarg(realpath($raiz))
   . ' ' . escapeshellarg(json_encode(['DIR_LOGS' => $cfgSim['DIR_LOGS'], 'DIR_SCRIPTS' => $cfgSim['DIR_SCRIPTS']])) . ' 2>&1', $salida, $codigo);
verificar("el proceso hijo terminó con exit(3) (código $codigo)", $codigo === 3);
verificar('la copia fue eliminada por el shutdown handler', copias($pdo) === []);
$ult = lab_bitacora($cfgSim, 1)[0] ?? ['resultado' => '', 'detalle' => ''];
verificar('y quedó una línea ERROR que lo explica', $ult['resultado'] === 'ERROR' && strpos($ult['detalle'], 'terminó antes') !== false && strpos($ult['detalle'], 'Copia eliminada: sí') !== false);
verificar('el bloqueo quedó libre', ($l = lab_bloquear($cfgSim)) !== false);
lab_desbloquear($l ?? null);

// ---------------------------------------------------------------------
echo "\n== 7. Bitácora ==\n";
$ruta = lab_ruta_log($cfg);
$log  = lab_bitacora($cfg, 50);
verificar('hay una línea por cada solicitud (rechazos incluidos)', count($log) === 10);   // 4 rechazos + límite + bloqueo + respaldo fallido + ensayo OK + excepción + proceso muerto
verificar('el archivo tiene permisos 600', is_file($ruta) && (fileperms($ruta) & 0777) === 0600);
verificar('todas las líneas tienen 7 campos', count(array_filter(file($ruta), fn($l) => count(explode(' | ', rtrim($l, "\n"))) === 7)) === count(file($ruta)));
$ok = array_values(array_filter($log, fn($b) => $b['resultado'] === 'OK'));
verificar('el ensayo completo quedó como OK con su respaldo y «Copia eliminada: sí»',
    count($ok) === 1 && strpos($ok[0]['detalle'], 'Respaldo previo: respaldo_') !== false && strpos($ok[0]['detalle'], 'Copia eliminada: sí') !== false);
verificar('los rechazos y fallos quedaron como ERROR', count($log) - count($ok) === count(array_filter($log, fn($b) => $b['resultado'] === 'ERROR')) && count($log) - count($ok) === 9);
lab_solicitar($pdo, $cfgSim, "Q01\n2026-01-01 | falso | falso | falso | falso | OK | linea inyectada", 'A');
verificar('un caso con salto de línea y "|" no puede falsificar una línea', count(file($ruta)) === count($log) + 1
    && count(array_filter(file($ruta), fn($l) => count(explode(' | ', rtrim($l, "\n"))) === 7)) === count(file($ruta)));
verificar('si no se puede escribir la bitácora, se devuelve false', lab_registrar(['DIR_LOGS' => '/proc/no_se_puede'], ['ok' => true, 'copia_eliminada' => null]) === false);

// Limpieza de lo temporal
foreach (glob($tmp . '/dba_logs/*') ?: [] as $f) { @unlink($f); }
@rmdir($tmp . '/dba_logs'); @unlink($tmp . '/scripts/backup.sh'); @rmdir($tmp . '/scripts'); @unlink($hijo); @rmdir($tmp);

verificar('al terminar no quedan copias sbx_ en la base', copias($pdo) === []);
echo "\n" . ($fallos === 0 ? 'Todo correcto.' : "Fallos: $fallos") . "\n";
exit($fallos === 0 ? 0 : 1);
