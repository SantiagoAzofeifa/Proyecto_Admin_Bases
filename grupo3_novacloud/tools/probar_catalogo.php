<?php
// Prueba por terminal de includes/catalogo_lib.php y includes/lab_catalogo.php.
// Uso (desde la carpeta de la app):  php grupo3_novacloud/tools/probar_catalogo.php
// (copiar a ~/public_html/proyecto/tools/ o ajustar la ruta del require).
// Solo LEE la base; no crea ni borra nada.
if (PHP_SAPI !== "cli") { http_response_code(404); exit; }
$raiz = is_file(__DIR__ . '/../../config.php') ? __DIR__ . '/../..' : __DIR__ . '/..';
require $raiz . '/config.php';
require $raiz . '/includes/catalogo_lib.php';
require $raiz . '/includes/diag_catalogo.php';
require $raiz . '/includes/lab_catalogo.php';

$pdo = conectar_bd();
$c   = cargar_config();

echo "== Tablas propias ==\n", implode(', ', cat_tablas($pdo)), "\n\n";

echo "== Relaciones declaradas ==\n";
$decl = cat_relaciones_declaradas($pdo);
foreach ($decl as $r) { echo "{$r['hijo']}.{$r['col_hijo']} -> {$r['padre']}.{$r['col_padre']}\n"; }
if (!$decl) { echo "(ninguna)\n"; }

echo "\n== Relaciones inferidas ==\n";
foreach (cat_inferir(cat_columnas($pdo), cat_llaves_primarias($pdo), $decl) as $r) {
    $n = cat_contar_huerfanos($pdo, $r);
    echo "{$r['hijo']}.{$r['col_hijo']} -> {$r['padre']}.{$r['col_padre']}  huerfanos: $n\n";
}

echo "\n== Rechazo de identificadores no validos ==\n";
var_dump(cat_buscar_relacion($pdo, 'diag_pedidos', 'id_cliente`; DROP', 'clientes', 'id_cliente'));
echo cat_ident('a`b'), "\n";

echo "\n== Laboratorio (solo lectura) ==\n";
echo "Limite de filas: ", lab_max_filas($c), "\n";
foreach (['Q01', 'Q02'] as $id) {
    foreach (['A', 'B', 'C', 'Z'] as $k) {
        $r = lab_resolver($id, $k);
        if ($r === null) { echo "$id/$k: RECHAZADO (fuera de la lista)\n"; continue; }
        echo "$id/$k: ", lab_sql_crear_indice($r[0], $r[1]), "\n";
    }
    [$caso] = lab_resolver($id, 'A');
    [$ok, $n, $m] = lab_verificar_limite($pdo, $c, $caso);
    echo "$id filas en origen: $n ", $ok ? '(dentro del limite)' : "($m)", "\n";
}
