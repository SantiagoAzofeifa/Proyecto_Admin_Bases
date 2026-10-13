<?php
// Menú principal - versión 1.0
require __DIR__ . '/includes/auth.php';
requerir_login();

$opciones = [
    ['1. Conexión y Estado del Servidor', 'Verificar el estado del motor de base de datos, versión activa y parámetros de conexión (PDO / MySQLi).', 'Gestionar Conexión', 'conexion.php'],
    ['2. Gestión de Esquemas y Tablas', 'Crear, modificar o listar tablas, definir tipos de datos y restricciones de integridad referencial.', 'Ver Esquemas', 'esquemas.php'],
    ['3. Usuarios y Privilegios (GRANT/REVOKE)', 'Administrar roles, control de accesos de usuarios y asignación de permisos sobre las bases de datos.', 'Control de Accesos', 'usuarios.php'],
    ['4. Respaldos y Restauración (Dump)', 'Generar respaldos lógicos de bases de datos mediante comandos del sistema y exportación de estructuras.', 'Respaldos', 'respaldos.php'],
    ['5. Monitoreo y Consultas (SQL)', 'Ejecutar consultas de diagnóstico, revisar procesos activos y analizar planes de ejecución (EXPLAIN).', 'Consola SQL', 'consola.php'],
    ['6. Bitácoras y Logs del Sistema', 'Revisar los registros de errores y auditoría del motor de base de datos desde el entorno web.', 'Ver Logs', 'logs.php'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel DBA - Gestor de Prácticas</title>
    <link rel="stylesheet" href="css/estilos.css">
</head>
<body>
    <header class="topbar">
        <h1>Panel DBA - Gestor de Prácticas <small>v<?= e(APP_VERSION) ?></small></h1>
        <div class="sesion">
            Usuario: <strong><?= e(usuario_actual()) ?></strong>
            <a href="logout.php" class="btn-salir">Cerrar Sesión</a>
        </div>
    </header>

    <div class="infobar">
        <span><strong>CURSO:</strong> <?= e(CURSO) ?></span>
        <span><strong>Profesor:</strong> <?= e(PROFESOR) ?></span>
    </div>

    <main class="contenedor">
        <h2>Menú Principal de Tareas de Administrador</h2>
        <p>Seleccione una opción de administración para ejecutar las prácticas de PostgreSQL / MariaDB:</p>

        <div class="grid">
            <?php foreach ($opciones as [$titulo, $desc, $boton, $enlace]): ?>
                <section class="tarjeta">
                    <h3><?= e($titulo) ?></h3>
                    <p><?= e($desc) ?></p>
                    <a class="btn" href="<?= e($enlace) ?>"><?= e($boton) ?></a>
                </section>
            <?php endforeach; ?>
        </div>
    </main>
</body>
</html>
