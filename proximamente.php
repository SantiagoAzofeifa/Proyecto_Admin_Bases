<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
requerir_login();

// Solo se aceptan valores 4 a 6; cualquier otra cosa cae en 4.
$p = filter_input(INPUT_GET, 'p', FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => 6]]) ?: 5;

render_header("Módulo 0$p", "p$p");
?>
<h2>Módulo 0<?= $p ?></h2>
<div class="tarjeta">
    <h3>Módulo disponible próximamente</h3>
    <p>Este espacio está reservado para una herramienta que se incorporará a la Consola DBA en las siguientes semanas.</p>
    <a class="btn" href="index.php">Volver al inicio</a>
</div>
<?php render_footer();
