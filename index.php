<?php
// Inicio de la Consola DBA - versión 2.0
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
requerir_login();

$cfg = cargar_config();

// [número, nombre, qué hace, efecto sobre la base, enlace]
// El efecto le dice al administrador, antes de entrar, si el módulo cambia algo.
$modulos = [
    ['01', 'Monitor', 'Tamaño de la base, tablas, registros y las tablas que más espacio consumen.', 'Solo lectura', 'lectura', 'monitor.php'],
    ['02', 'Respaldos', 'Genera respaldos comprimidos con mariadb-dump, los lista y muestra la bitácora.', 'Escribe archivos de respaldo', 'escribe', 'respaldos.php'],
    ['03', 'Diagnóstico', 'Plan de ejecución de Q01 y Q02, su interpretación y la comparación antes y después de una mejora.', 'Solo lectura', 'lectura', 'diagnostico.php'],
    ['04', 'Mantenimiento', 'Panel de tablas y actualización de estadísticas de una tabla con ANALYZE TABLE.', 'Actualiza estadísticas', 'escribe', 'mantenimiento.php'],
    ['05', 'Cartógrafo', 'Mapa de relaciones declaradas e inferidas entre tablas y conteo de filas huérfanas.', 'Solo lectura', 'lectura', 'cartografo.php'],
    ['06', 'Laboratorio', 'Ensaya un índice candidato en una copia temporal y compara el plan antes y después.', 'Copia temporal sbx_', 'ddl', 'laboratorio.php'],
];

render_header('Inicio', 'inicio');
?>
<header class="portada">
    <p class="portada-ante">Consola conectada a la base</p>
    <h2 class="portada-base ident"><?= e((string) $cfg['DB_NAME']) ?></h2>
    <p class="portada-datos">
        <span><?= e((string) $cfg['DB_MOTOR']) ?></span>
        <span>usuario <span class="ident"><?= e((string) $cfg['DB_USER']) ?></span></span>
        <span>versión <?= e(APP_VERSION) ?> de la consola</span>
    </p>
</header>

<h3 class="seccion-titulo">Módulos</h3>
<ol class="indice-modulos">
<?php foreach ($modulos as [$num, $nombre, $desc, $efecto, $tipoEfecto, $enlace]): ?>
    <li>
        <a href="<?= e($enlace) ?>" class="modulo-fila">
            <span class="modulo-num" aria-hidden="true"><?= e($num) ?></span>
            <span class="modulo-cuerpo">
                <span class="modulo-nombre"><span class="sr">Módulo <?= e($num) ?>: </span><?= e($nombre) ?></span>
                <span class="modulo-desc"><?= e($desc) ?></span>
            </span>
            <span class="efecto efecto-<?= e($tipoEfecto) ?>"><?= e($efecto) ?></span>
        </a>
    </li>
<?php endforeach; ?>
</ol>
<?php render_footer();
