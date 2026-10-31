<?php
function menu_items(): array
{
    return [
        'inicio'    => ['Inicio', 'index.php'],
        'monitor'     => ['Módulo 01 · Monitor', 'monitor.php'],
        'respaldos'   => ['Módulo 02 · Respaldos', 'respaldos.php'],
        'diagnostico' => ['Módulo 03 · Diagnóstico', 'diagnostico.php'],
        'mantenimiento' => ['Módulo 04    Mantenimiento', 'mantenimiento.php'],
        'p5' => ['Módulo 05', 'proximamente.php?p=5'],
        'p6' => ['Módulo 06', 'proximamente.php?p=6'],
    ];
}

function render_header(string $titulo, string $activo = 'inicio'): void
{
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> · <?= e(APP_NOMBRE) ?></title>
<link rel="icon" type="image/png" href="img/favicon.png">
<link rel="stylesheet" href="css/estilos.css">
</head>
<body class="con-pie">
<header class="topbar">
    <div class="marca">
        <img src="img/favicon.png" alt="NovaCloud" class="logo-top">
        <h1><?= e(APP_NOMBRE) ?> <small>v<?= e(APP_VERSION) ?></small></h1>
    </div>
    <div class="sesion">
        Usuario: <strong><?= e(usuario_actual() ?? '') ?></strong>
        <a href="logout.php" class="btn-salir">Cerrar sesión</a>
    </div>
</header>
<nav class="menu" aria-label="Menú principal">
<?php foreach (menu_items() as $clave => [$etiqueta, $enlace]): ?>
    <a href="<?= e($enlace) ?>" class="<?= $clave === $activo ? 'activo' : '' ?>"><?= e($etiqueta) ?></a>
<?php endforeach; ?>
</nav>
<main class="contenedor">
<?php
}

function render_footer(): void
{
    $c = cargar_config();
    ?>
</main>
<footer class="pie">
    <span><strong>Motor:</strong> <?= e((string) $c['DB_MOTOR']) ?></span>
    <span><strong>Base de datos:</strong> <?= e((string) $c['DB_NAME']) ?></span>
    <span><strong>Usuario de conexión:</strong> <?= e((string) $c['DB_USER']) ?></span>
    <span><strong>Consola DBA</strong> v<?= e(APP_VERSION) ?></span>
</footer>
</body>
</html>
<?php
}
