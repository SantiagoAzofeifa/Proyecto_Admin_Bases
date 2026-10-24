<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/monitor_lib.php';
requerir_login();

$cfg = cargar_config();

// POST -> Redirect -> GET (evita reenviar con F5)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $_SESSION['flash'] = ['error', 'Solicitud no válida. Intente de nuevo.'];
    } elseif (($_POST['accion'] ?? '') === 'analizar') {   // única acción permitida
        try {
            [$n, $errores] = monitor_analizar(conectar_bd());
            $_SESSION['flash'] = $errores
                ? ['error', 'ANALYZE TABLE reportó errores: ' . implode('; ', $errores)]
                : ['ok', "Estadísticas actualizadas en $n tablas (ANALYZE TABLE)."];
        } catch (PDOException $ex) {
            $_SESSION['flash'] = ['error', 'No se pudieron actualizar las estadísticas: ' . $ex->getMessage()];
        }
    }
    header('Location: monitor.php');
    exit;
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$error = null;
try {
    $pdo      = conectar_bd();
    $conexion = monitor_conexion($pdo);
    $bd       = monitor_tamano_bd($pdo);
    $tablas   = monitor_tablas($pdo, $bd['bytes']);
} catch (PDOException $ex) {
    // El mensaje de PDO no incluye la contraseña (solo usuario y host).
    $error = $ex->getMessage();
}

render_header('Monitor', 'monitor');
?>
<h2>Proyecto 01 · Monitor de Base de Datos</h2>
<p>Espacio y contenido de <strong><?= e((string) $cfg['DB_NAME']) ?></strong>, consultados en vivo desde <code>information_schema</code>.</p>

<?php if ($flash): ?>
    <div class="aviso aviso-<?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

<?php if ($error !== null): ?>
    <div class="aviso aviso-error">No se pudo consultar la base de datos: <?= e($error) ?></div>
<?php render_footer(); exit; endif; ?>

<?php
$total_exactas = array_sum(array_map(fn($t) => $t['exactas'] ?? $t['estimadas'], $tablas));
$top = array_filter($tablas, fn($t) => $t['top']);
?>

<div class="barra-accion monitor-acciones">
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="accion" value="analizar">
        <button type="submit" class="btn-accion">Actualizar estadísticas</button>
    </form>
    <a class="btn-secundario" href="monitor.php">Volver a consultar</a>
</div>

<div class="resumen">
    <div class="dato"><strong><?= e((string) $cfg['DB_MOTOR']) ?></strong>motor · <?= e($conexion['version']) ?></div>
    <div class="dato"><strong><?= e($conexion['base']) ?></strong>base de datos</div>
    <div class="dato"><strong><?= e($conexion['usuario']) ?></strong>usuario conectado</div>
    <div class="dato"><strong><?= e(date('d/m/Y H:i:s', strtotime($conexion['fecha']))) ?></strong>fecha de la consulta</div>
</div>

<div class="resumen">
    <div class="dato dato-grande"><strong><?= e(monitor_tam($bd['bytes'])) ?></strong>tamaño aproximado de la BD</div>
    <div class="dato dato-grande"><strong><?= $bd['tablas'] ?></strong>tablas</div>
    <div class="dato dato-grande"><strong><?= e(monitor_num($total_exactas)) ?></strong>registros en total</div>
</div>

<?php if ($top): ?>
<h3 class="seccion-titulo">Tablas que consumen mayor espacio</h3>
<div class="top-tablas">
<?php foreach ($top as $t): ?>
    <div class="top-fila">
        <span class="top-nombre"><?= e($t['tabla']) ?></span>
        <span class="barra"><span style="width: <?= number_format($t['pct'], 1, '.', '') ?>%"></span></span>
        <span class="top-valor"><?= e(monitor_tam($t['total'])) ?> · <?= number_format($t['pct'], 1, ',', '.') ?>%</span>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<h3 class="seccion-titulo">Listado de tablas</h3>
<div class="tabla-wrap">
<table class="tabla">
    <thead>
        <tr>
            <th>Tabla</th><th>Motor</th>
            <th class="num">Registros</th><th class="num">Estimado</th>
            <th class="num">Datos</th><th class="num">Índices</th><th class="num">Tamaño</th><th class="num">% de la BD</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$tablas): ?>
        <tr><td colspan="8">La base no tiene tablas.</td></tr>
    <?php endif; ?>
    <?php foreach ($tablas as $t): ?>
        <tr class="<?= $t['top'] ? 'fila-top' : '' ?>">
            <td><?= e($t['tabla']) ?><?= $t['top'] ? ' <span class="etiqueta">mayor espacio</span>' : '' ?></td>
            <td><?= e($t['motor']) ?></td>
            <td class="num"><?= $t['exactas'] === null ? '—' : e(monitor_num($t['exactas'])) ?></td>
            <td class="num">≈ <?= e(monitor_num($t['estimadas'])) ?></td>
            <td class="num"><?= e(monitor_tam($t['datos'])) ?></td>
            <td class="num"><?= e(monitor_tam($t['indices'])) ?></td>
            <td class="num"><strong><?= e(monitor_tam($t['total'])) ?></strong></td>
            <td class="num"><?= number_format($t['pct'], 1, ',', '.') ?>%</td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <th colspan="2">Total</th>
            <th class="num"><?= e(monitor_num($total_exactas)) ?></th>
            <th class="num">≈ <?= e(monitor_num(array_sum(array_column($tablas, 'estimadas')))) ?></th>
            <th class="num"><?= e(monitor_tam(array_sum(array_column($tablas, 'datos')))) ?></th>
            <th class="num"><?= e(monitor_tam(array_sum(array_column($tablas, 'indices')))) ?></th>
            <th class="num"><?= e(monitor_tam($bd['bytes'])) ?></th>
            <th class="num">100%</th>
        </tr>
    </tfoot>
</table>
</div>

<p class="total-bd">Tamaño total aproximado de la BD: <strong><?= e(monitor_tam($bd['bytes'])) ?></strong></p>

<div class="notas">
    <p><strong>Registros</strong> es el conteo exacto con <code>COUNT(*)</code> (solo en tablas de hasta <?= e(monitor_num(MONITOR_LIMITE_CONTEO)) ?> filas estimadas).
       <strong>Estimado</strong> es <code>TABLE_ROWS</code>: en InnoDB es una estadística aproximada que se recalcula con <em>Actualizar estadísticas</em> (<code>ANALYZE TABLE</code>).</p>
    <p><strong>Tamaño</strong> = <code>DATA_LENGTH + INDEX_LENGTH</code>. InnoDB reserva el espacio en páginas de 16 KB, por eso una tabla pequeña nunca ocupa menos de 16 KB.</p>
</div>

<details class="consultas">
    <summary>Consultas SQL utilizadas</summary>
    <?php foreach (MONITOR_SQL as $nombre => $sql): ?>
        <h4><?= e($nombre) ?></h4>
        <pre><?= e($sql) ?></pre>
    <?php endforeach; ?>
</details>
<?php render_footer();
