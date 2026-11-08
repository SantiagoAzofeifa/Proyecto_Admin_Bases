<?php
// Prueba por terminal de la parte de Santiago en los Módulos 05 y 06:
//   includes/rel_inferencia.php  (inferencia de relaciones por nombre y tipo)
//   includes/lab_ensayo.php      (copia, índice y EXPLAIN comparativo)
//
// Uso, desde la carpeta de la app o desde el clon del repositorio:
//   php grupo3_novacloud/tools/probar_santiago_05_06.php            # Q01, candidato A
//   php grupo3_novacloud/tools/probar_santiago_05_06.php Q02 A      # otro caso/candidato
//
// La sección 1 no usa la base. Las secciones 2 y 3 leen la base de dba.conf;
// el ensayo crea y elimina sbx_diag_pedidos y NO modifica diag_pedidos.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$raiz = is_file(__DIR__ . '/../../config.php') ? __DIR__ . '/../..' : __DIR__ . '/..';
require $raiz . '/config.php';
require $raiz . '/includes/monitor_lib.php';
require $raiz . '/includes/catalogo_lib.php';
require $raiz . '/includes/diag_catalogo.php';
require $raiz . '/includes/diag_lib.php';
require $raiz . '/includes/lab_catalogo.php';
require $raiz . '/includes/lab_ensayo.php';

$fallos = 0;
function verificar(string $que, bool $ok): void
{
    global $fallos;
    echo ($ok ? '  [OK]    ' : '  [FALLO] ') . $que . "\n";
    $fallos += $ok ? 0 : 1;
}
function tabla_plan(array $plan): void
{
    foreach ($plan as $f) {
        printf("    %-18s type=%-6s key=%-24s rows=%-7s Extra=%s\n", $f['table'], $f['type'],
               $f['key'] ?? 'NULL', $f['rows'], $f['Extra'] ?? '');
    }
}

// ---------------------------------------------------------------------
echo "== 1. Reglas de inferencia con un catálogo de ejemplo (sin base) ==\n";
$u = 'int(10) unsigned';
$cols = [
    'clientes'    => [['col' => 'id_cliente', 'completo' => $u], ['col' => 'correo', 'completo' => 'varchar(120)']],
    'productos'   => [['col' => 'id', 'completo' => 'int(11)']],
    'pedidos'     => [['col' => 'id_pedido', 'completo' => $u], ['col' => 'id_cliente', 'completo' => $u],
                      ['col' => 'producto_id', 'completo' => 'int(11)']],
    'detalle'     => [['col' => 'id_pedido', 'completo' => $u], ['col' => 'linea', 'completo' => 'smallint(6)']],
    'historial'   => [['col' => 'id_historial', 'completo' => $u], ['col' => 'id_cliente', 'completo' => 'bigint(20) unsigned']],
    'notas'       => [['col' => 'id_nota', 'completo' => $u], ['col' => 'id_cliente', 'completo' => 'varchar(20)']],
    'pagos'       => [['col' => 'id_pago', 'completo' => $u], ['col' => 'id_cliente', 'completo' => 'int(11)']],
    'sbx_pedidos' => [['col' => 'id_pedido', 'completo' => $u], ['col' => 'id_cliente', 'completo' => $u]],
];
$pks = ['clientes' => ['id_cliente'], 'productos' => ['id'], 'pedidos' => ['id_pedido'],
        'detalle' => ['id_pedido', 'linea'], 'historial' => ['id_historial'], 'notas' => ['id_nota'],
        'pagos' => ['id_pago'], 'sbx_pedidos' => ['id_pedido']];
$det = rel_inferir_detalle($cols, $pks, []);
$buscar = function (array $lista, string $h, string $c) {
    foreach ($lista as $r) {
        if ($r['hijo'] === $h && $r['col_hijo'] === $c) {
            return $r;
        }
    }
    return null;
};
$r = $buscar($det['relaciones'], 'pedidos', 'id_cliente');
verificar('pedidos.id_cliente -> clientes: nombre exacto y mismo tipo, confianza alta',
          $r && $r['padre'] === 'clientes' && $r['confianza'] === 'alta');
$r = $buscar($det['relaciones'], 'pedidos', 'producto_id');
verificar('pedidos.producto_id -> productos.id: patrón de nombre, confianza media',
          $r && $r['padre'] === 'productos' && $r['regla'] === 'patron' && $r['confianza'] === 'media');
$r = $buscar($det['relaciones'], 'detalle', 'id_pedido');
verificar('detalle.id_pedido -> pedidos: se acepta aunque sea parte de una llave compuesta',
          $r && $r['padre'] === 'pedidos');
$r = $buscar($det['relaciones'], 'historial', 'id_cliente');
verificar('historial.id_cliente (bigint unsigned) -> clientes: enteros de distinto tamaño, confianza media',
          $r && $r['confianza'] === 'media');
verificar('notas.id_cliente (varchar) se descarta por tipo de distinta familia',
          !$buscar($det['relaciones'], 'notas', 'id_cliente') && $buscar($det['descartadas'], 'notas', 'id_cliente'));
verificar('pagos.id_cliente (int con signo) se descarta por distinto signo',
          !$buscar($det['relaciones'], 'pagos', 'id_cliente') && $buscar($det['descartadas'], 'pagos', 'id_cliente'));
verificar('la copia sbx_pedidos se ignora', !$buscar($det['relaciones'], 'sbx_pedidos', 'id_cliente'));
verificar('clientes.id_cliente (su propia llave primaria) no se propone',
          !$buscar($det['relaciones'], 'clientes', 'id_cliente'));
$sinDecl = rel_inferir($cols, $pks, [['hijo' => 'pedidos', 'col_hijo' => 'id_cliente', 'padre' => 'clientes', 'col_padre' => 'id_cliente']]);
verificar('una relación ya declarada como FOREIGN KEY no se vuelve a proponer', !$buscar($sinDecl, 'pedidos', 'id_cliente'));
foreach ($det['descartadas'] as $d) {
    echo "    descartada: {$d['hijo']}.{$d['col_hijo']} -> {$d['padre']}. {$d['motivo']}\n";
}

// ---------------------------------------------------------------------
$pdo = conectar_bd();
$c   = cargar_config();
$con = monitor_conexion($pdo);
echo "\n== 2. Relaciones de la base real ==\n";
echo "  Servidor {$con['version']} · base {$con['base']} · usuario {$con['usuario']}\n";
$decl = cat_relaciones_declaradas($pdo);
echo "  Declaradas (FOREIGN KEY): " . count($decl) . "\n";
foreach ($decl as $d) {
    echo "    {$d['hijo']}.{$d['col_hijo']} -> {$d['padre']}.{$d['col_padre']}\n";
}
$real = rel_inferir_detalle(cat_columnas($pdo), cat_llaves_primarias($pdo), $decl);
echo "  Inferidas: " . count($real['relaciones']) . "\n";
foreach ($real['relaciones'] as $i) {
    $valida = cat_buscar_relacion($pdo, $i['hijo'], $i['col_hijo'], $i['padre'], $i['col_padre']) !== null;
    $n = cat_contar_huerfanos($pdo, $i);
    printf("    %s.%s -> %s.%s  regla=%s confianza=%s huérfanos=%d %s\n      %s\n",
           $i['hijo'], $i['col_hijo'], $i['padre'], $i['col_padre'], $i['regla'], $i['confianza'], $n,
           $n === 0 ? '(OK)' : '(ADVERTENCIA)', $i['motivo']);
    verificar("{$i['hijo']}.{$i['col_hijo']} pasa la validación de cat_buscar_relacion()", $valida);
}
foreach ($real['descartadas'] as $d) {
    echo "    descartada: {$d['hijo']}.{$d['col_hijo']} -> {$d['padre']}. {$d['motivo']}\n";
}

// ---------------------------------------------------------------------
$caso = strtoupper($argv[1] ?? 'Q01');
$cand = strtoupper($argv[2] ?? 'A');
echo "\n== 3. Laboratorio de índices: ensayo $caso / candidato $cand ==\n";
$idxAntes = diag_indices($pdo, ['diag_pedidos']);
$e = lab_ensayar($pdo, $c, $caso, $cand);
if (!$e['ok']) {
    echo "  ERROR: {$e['error']}\n";
    $fallos++;
} else {
    echo "  Tabla {$e['tabla']} ({$e['filas_origen']} filas) copiada en {$e['copia']} ({$e['filas_copia']} filas)\n";
    echo '  Índices heredados quitados de la copia: ' . ($e['indices_quitados'] ? implode(', ', $e['indices_quitados']) : 'ninguno') . "\n";
    echo "  Índice candidato: {$e['indice']}\n    {$e['sql_indice']}\n";
    echo "  Plan ANTES (sin índice):\n";
    tabla_plan($e['plan_antes']);
    echo "  Plan DESPUÉS (con el candidato):\n";
    tabla_plan($e['plan_despues']);
    echo "  Diferencias:\n";
    foreach ($e['diferencias'] as $d) {
        echo "    - $d\n";
    }
    echo '  ¿El optimizador usa el índice candidato? ' . ($e['usa_indice'] ? 'sí' : 'no') . "\n";
    verificar('la copia tiene las mismas filas que la tabla original', $e['filas_copia'] === $e['filas_origen']);
    verificar('el resumen de la consulta es igual antes y después', $e['resumen_igual']);
}
verificar('la copia se eliminó al terminar', $e['copia_eliminada'] !== false);
$restos = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'sbx\\_%'")->fetchColumn();
verificar('no quedan tablas sbx_ en la base', (int) $restos === 0);
verificar('los índices de diag_pedidos (la tabla real) no cambiaron', diag_indices($pdo, ['diag_pedidos']) == $idxAntes);
echo "  Duración: {$e['duracion_ms']} ms\n";

echo "\n== 4. Rechazos sin ejecutar SQL ==\n";
$z = lab_ensayar($pdo, $c, 'Q01', 'Z');
verificar("candidato Z fuera de la lista: «{$z['error']}»", !$z['ok'] && $z['copia_eliminada'] === null);
$z = lab_ensayar($pdo, $c, "Q01'; DROP TABLE diag_pedidos; --", 'A');
verificar('caso con texto inyectado se rechaza', !$z['ok'] && $z['copia_eliminada'] === null);

echo "\n" . ($fallos === 0 ? 'Todo correcto.' : "Fallos: $fallos") . "\n";
exit($fallos === 0 ? 0 : 1);
