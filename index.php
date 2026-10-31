<?php
// Inicio de la Consola DBA - versión 1.0
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
requerir_login();

$modulos = [
    ['Módulo 01 · Monitor de Base de Datos', 'Tamaño de la base, tablas, registros y tablas que más espacio consumen.', 'Abrir Monitor', 'monitor.php', true],
    ['Módulo 02 · Respaldos', 'Generar respaldos comprimidos, listarlos y consultar la bitácora de ejecución.', 'Abrir Respaldos', 'respaldos.php', true],
    ['Módulo 03 · Diagnóstico de consultas', 'Planes de ejecución (EXPLAIN) de Q01 y Q02, interpretación y comparación antes/después de una mejora.', 'Abrir Diagnóstico', 'diagnostico.php', true],
    ['Módulo 04    Mantenimiento', 'Panel de tablas, actualización de estadisticas de una tabla (ANALYZE TABLE) y bitácora de mantenimiento', 'Abrir Mantenimiento', 'mantenimiento.php', true],
    ['Módulo 05', 'Módulo disponible próximamente.', 'Ver detalle', 'proximamente.php?p=5', false],
    ['Módulo 06', 'Módulo disponible próximamente.', 'Ver detalle', 'proximamente.php?p=6', false],
];

render_header('Inicio', 'inicio');
?>
<h2>Bienvenido a la Consola DBA</h2>
<p>Seleccione un módulo de administración. Los módulos 04 a 06 se habilitarán en las próximas semanas.</p>

<div class="grid">
<?php foreach ($modulos as [$titulo, $desc, $boton, $enlace, $habilitado]): ?>
    <section class="tarjeta <?= $habilitado ? '' : 'pendiente' ?>">
        <h3><?= e($titulo) ?></h3>
        <p><?= e($desc) ?></p>
        <a class="btn" href="<?= e($enlace) ?>"><?= e($boton) ?></a>
    </section>
<?php endforeach; ?>
</div>
<?php render_footer();
