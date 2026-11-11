<?php
/** Menú común: clave => [número de módulo, nombre, enlace]. Inicio no lleva número. */
function menu_items(): array
{
    return [
        'inicio'        => ['',   'Inicio',        'index.php'],
        'monitor'       => ['01', 'Monitor',       'monitor.php'],
        'respaldos'     => ['02', 'Respaldos',     'respaldos.php'],
        'diagnostico'   => ['03', 'Diagnóstico',   'diagnostico.php'],
        'mantenimiento' => ['04', 'Mantenimiento', 'mantenimiento.php'],
        'cartografo'    => ['05', 'Cartógrafo',    'cartografo.php'],
        'laboratorio'   => ['06', 'Laboratorio',   'laboratorio.php'],
    ];
}

/** Encabezado de un módulo: número grande y nombre. */
function titulo_modulo(string $num, string $nombre): void
{
    ?>
<h2 class="titulo-modulo"><span class="titulo-num"><span class="sr">Módulo </span><?= e($num) ?></span> <span class="titulo-nombre"><?= e($nombre) ?></span></h2>
<?php
}

/** Tipografías y tema; se comparte con login.php. */
function render_head_comun(): void
{
    ?>
<link rel="icon" type="image/png" href="img/favicon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,300..800&family=JetBrains+Mono:wght@400;600&display=swap">
<link rel="stylesheet" href="css/estilos.css">
<script>
// Aplica el tema guardado antes de pintar, para que no parpadee.
document.documentElement.classList.add('js');
try { var t = localStorage.getItem('consola-tema'); if (t === 'claro' || t === 'oscuro') document.documentElement.dataset.tema = t; } catch (e) {}
</script>
<script src="js/consola.js" defer></script>
<?php
}

function render_header(string $titulo, string $activo = 'inicio'): void
{
    $c = cargar_config();
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> | <?= e(APP_NOMBRE) ?></title>
<?php render_head_comun(); ?>
</head>
<body class="app">
<a class="saltar" href="#contenido">Ir al contenido</a>
<aside class="lateral" id="lateral">
    <div class="lateral-marca">
        <a href="index.php" class="marca">
            <img src="img/favicon.png" alt="" class="marca-logo" width="36" height="36">
            <span class="marca-texto"><?= e(APP_NOMBRE) ?> <span class="marca-ver">v<?= e(APP_VERSION) ?></span></span>
        </a>
        <button type="button" class="btn-menu" aria-controls="menu-principal" aria-expanded="false">
            <span class="sr">Abrir menú</span><span class="btn-menu-barras" aria-hidden="true"></span>
        </button>
    </div>
    <nav class="menu" id="menu-principal" aria-label="Menú principal">
    <?php foreach (menu_items() as $clave => [$num, $nombre, $enlace]): ?>
        <a href="<?= e($enlace) ?>" class="menu-item<?= $clave === $activo ? ' activo' : '' ?>"<?= $clave === $activo ? ' aria-current="page"' : '' ?>>
            <span class="menu-num" aria-hidden="true"><?= e($num) ?></span>
            <span class="menu-nombre"><?php if ($num !== ''): ?><span class="sr">Módulo <?= e($num) ?> </span><?php endif; ?><?= e($nombre) ?></span>
        </a>
    <?php endforeach; ?>
    </nav>
    <section class="conexion" aria-label="Conexión actual">
        <p class="conexion-titulo"><span class="punto-vivo" aria-hidden="true"></span>Conectado a</p>
        <dl>
            <div><dt>Motor</dt><dd><?= e((string) $c['DB_MOTOR']) ?></dd></div>
            <div><dt>Base</dt><dd class="ident"><?= e((string) $c['DB_NAME']) ?></dd></div>
            <div><dt>Usuario</dt><dd class="ident"><?= e((string) $c['DB_USER']) ?></dd></div>
        </dl>
        <div class="conexion-acciones">
            <span class="sesion-usuario" title="Usuario de la consola"><?= e(usuario_actual() ?? '') ?></span>
            <button type="button" class="btn-tema" aria-label="Cambiar tema claro u oscuro" title="Cambiar tema"></button>
            <a href="logout.php" class="btn-salir">Cerrar sesión</a>
        </div>
    </section>
</aside>
<main class="contenedor" id="contenido" tabindex="-1">
<?php
}

function render_footer(): void
{
    $c = cargar_config();
    ?>
</main>
<footer class="pie">
    <span><strong>Motor</strong> <?= e((string) $c['DB_MOTOR']) ?></span>
    <span><strong>Base de datos</strong> <?= e((string) $c['DB_NAME']) ?></span>
    <span><strong>Usuario de conexión</strong> <?= e((string) $c['DB_USER']) ?></span>
    <span><strong>Consola DBA</strong> v<?= e(APP_VERSION) ?></span>
</footer>
</body>
</html>
<?php
}
