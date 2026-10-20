<?php
require __DIR__ . '/includes/auth.php';
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
    <title>Acceso al Sistema</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <link rel="stylesheet" href="css/estilos.css">
</head>
<body class="login-body">
    <main class="login-card">
        <img src="img/novacloud.png" alt="NovaCloud" class="logo-login">
        <h1>Acceso al Sistema</h1>
        <p class="curso">
            <strong>CURSO:</strong> <?= e(CURSO) ?><br>
            <strong>Prof:</strong> <?= e(PROFESOR) ?>
        </p>

        <?php if ($error): ?>
            <div class="alerta"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <label for="usuario">Usuario:</label>
            <input type="text" id="usuario" name="usuario" required autofocus>

            <label for="clave">Contraseña:</label>
            <input type="password" id="clave" name="clave" required>

            <button type="submit" class="btn-principal">Ingresar al Panel</button>
        </form>
    </main>
</body>
</html>
