<?php
// Inicio de la Consola DBA - versión 1.0
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
requerir_login();

$modulos = [
    ['Proyecto 01 · Monitor de Base de Datos', 'Tamaño de la base, tablas, registros y tablas que más espacio consumen.', 'Abrir Monitor', 'monitor.php', true],
    ['Proyecto 02 · Respaldos', 'Generar respaldos comprimidos, listarlos y consultar la bitácora de ejecución.', 'Abrir Respaldos', 'respaldos.php', true],
    ['Proyecto 03', 'Módulo disponible próximamente.', 'Ver detalle', 'proximamente.php?p=3', false],
    ['Proyecto 04', 'Módulo disponible próximamente.', 'Ver detalle', 'proximamente.php?p=4', false],
    ['Proyecto 05', 'Módulo disponible próximamente.', 'Ver detalle', 'proximamente.php?p=5', false],
    ['Proyecto 06', 'Módulo disponible próximamente.', 'Ver detalle', 'proximamente.php?p=6', false],
];

render_header('Inicio', 'inicio');
?>
<h2>Bienvenido a la Consola DBA</h2>
<p>Seleccione un módulo de administración. Los módulos 03 a 06 se habilitarán en las próximas semanas.</p>

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