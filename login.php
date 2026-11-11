<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
iniciar_sesion();

// Ya autenticado: directo al menú.
if (usuario_actual() !== null) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $clave   = $_POST['clave'] ?? '';

    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'La sesión del formulario expiró. Intente de nuevo.';
    } elseif (validar_credenciales($usuario, $clave)) {
        session_regenerate_id(true);          
        $_SESSION['usuario'] = $usuario;
        $_SESSION['ultimo_acceso'] = time();
        unset($_SESSION['csrf']);
        header('Location: index.php');        
        exit;
    } else {
        usleep(400000);                       
        $error = 'Usuario o contraseña incorrectos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acceso | <?= e(APP_NOMBRE) ?></title>
    <?php render_head_comun(); ?>
</head>
<body class="login-body">
    <aside class="login-lado" aria-hidden="true">
        <!-- Esquema ilustrativo: tablas unidas por relaciones, con la estrella de NovaCloud en la relación descubierta. -->
        <svg class="login-esquema" viewBox="0 0 420 300" focusable="false">
            <path class="le-rel" d="M126 78 C180 78 180 150 236 150"/>
            <path class="le-rel" d="M126 220 C180 220 180 162 236 162"/>
            <path class="le-rel le-inf" d="M336 156 C380 156 380 60 330 60"/>
            <g class="le-tabla"><rect x="20" y="52" width="106" height="62" rx="8"/><line x1="20" y1="74" x2="126" y2="74"/><rect class="le-col" x="32" y="84" width="58" height="6" rx="3"/><rect class="le-col" x="32" y="98" width="76" height="6" rx="3"/></g>
            <g class="le-tabla"><rect x="20" y="194" width="106" height="62" rx="8"/><line x1="20" y1="216" x2="126" y2="216"/><rect class="le-col" x="32" y="226" width="70" height="6" rx="3"/><rect class="le-col" x="32" y="240" width="50" height="6" rx="3"/></g>
            <g class="le-tabla le-centro"><rect x="236" y="124" width="100" height="76" rx="8"/><line x1="236" y1="146" x2="336" y2="146"/><rect class="le-col" x="248" y="156" width="64" height="6" rx="3"/><rect class="le-col" x="248" y="170" width="44" height="6" rx="3"/><rect class="le-col" x="248" y="184" width="70" height="6" rx="3"/></g>
            <g class="le-tabla"><rect x="250" y="30" width="80" height="50" rx="8"/><line x1="250" y1="50" x2="330" y2="50"/><rect class="le-col" x="262" y="60" width="46" height="6" rx="3"/></g>
            <path class="le-estrella" d="M381 108 l4 11 l11 4 l-11 4 l-4 11 l-4 -11 l-11 -4 l11 -4 z"/>
        </svg>
        <p class="login-lema">Monitorear, respaldar, diagnosticar y mantener una base MariaDB desde un mismo lugar.</p>
    </aside>
    <main class="login-card">
        <img src="img/novacloud.png" alt="NovaCloud" class="logo-login" width="180" height="105">
        <h1>Consola DBA</h1>
        <p class="curso"><?= e(CURSO) ?><br>Profesor <?= e(PROFESOR) ?></p>

        <?php if ($error): ?>
            <div class="alerta" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <label for="usuario">Usuario</label>
            <input type="text" id="usuario" name="usuario" required autofocus autocapitalize="none" spellcheck="false">

            <label for="clave">Contraseña</label>
            <input type="password" id="clave" name="clave" required>

            <button type="submit" class="btn-principal">Ingresar</button>
        </form>
    </main>
</body>
</html>
