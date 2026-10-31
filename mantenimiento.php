<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/monitor_lib.php';
require __DIR__ . '/includes/mantenimiento_lib.php';
requerir_login();
date_default_timezone_set('America/Costa_Rica');

$cfg = cargar_config();

// POST -> Redirect -> GET. Única acción permitida: 'analizar' sobre UNA tabla.
// Exige token CSRF; la tabla se valida contra el catálogo antes de tocar el motor.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tabla = $_POST['tabla'] ?? '';
    $tabla = is_string($tabla) ? $tabla : '';
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $_SESSION['flash'] = ['error', 'Solicitud no válida. Intente de nuevo.'];
    } elseif (($_POST['accion'] ?? '') !== 'analizar') {
        $_SESSION['flash'] = ['error', 'Acción no permitida.'];
    } else {
        try {
            $_SESSION['mant_resultado'] = mant_solicitar(conectar_bd(), $cfg, $tabla);
        } catch (PDOException $ex) {
            $_SESSION['flash'] = ['error', 'No se pudo conectar a la base de datos.'];
        }
    }
    header('Location: mantenimiento.php');
    exit;
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$resultado = $_SESSION['mant_resultado'] ?? null;   // se conserva hasta la siguiente operación

$error     = null;
$confirmar = null;     // tabla pendiente de confirmar
try {
    $pdo      = conectar_bd();
    $con      = monitor_conexion($pdo);
    $bitacora = mant_bitacora($cfg);
    $panel    = mant_panel($pdo, mant_ultimos_analisis($bitacora));

    if (isset($_GET['tabla'])) {
        $pedida = is_string($_GET['tabla']) ? $_GET['tabla'] : '';
        [$valida, $motivo] = mant_validar($pdo, $pedida);
        if ($valida) {
            $confirmar = $pedida;
        } else {
            $flash = ['error', $motivo];
        }
    }
} catch (PDOException $ex) {
    // El mensaje de PDO no incluye la contraseña (solo usuario y host).
    $error = 'No se pudo consultar la base de datos: ' . $ex->getMessage();
}

$nd = '<span class="no-disp">No disponible</span>';

render_header('Mantenimiento', 'mantenimiento');
?>
<h2>Módulo 04 · Mantenimiento básico</h2>
<p>Estadísticas de las tablas propias y actualización de una tabla seleccionada con <code>ANALYZE TABLE</code>.</p>

<?php if ($flash): ?>
    <div class="aviso aviso-<?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

<?php if ($error !== null): ?>
    <div class="aviso aviso-error"><?= e($error) ?></div>
<?php render_footer(); exit; endif; ?>

<div class="resumen">
    <div class="dato"><strong><?= e((string) $cfg['DB_MOTOR']) ?></strong>motor · servidor <?= e($con['version']) ?></div>
    <div class="dato"><strong><?= e($con['base']) ?></strong>base de datos</div>
    <div class="dato"><strong><?= e($con['usuario']) ?></strong>usuario conectado</div>
    <div class="dato"><strong><?= e(date('d/m/Y H:i:s', strtotime($con['fecha']))) ?></strong>fecha de la consulta</div>
    <div class="dato"><strong>v<?= e(APP_VERSION) ?></strong>Consola DBA</div>
</div>

<?php if ($confirmar !== null): ?>
<section class="bloque confirmar">
    <h3>Confirmar actualización de estadísticas</h3>
    <p><strong>Base de datos:</strong> <?= e($con['base']) ?> · <strong>Tabla:</strong> <?= e($confirmar) ?></p>
    <p>Se ejecutará <code>ANALYZE TABLE `<?= e($confirmar) ?>`</code>. La operación vuelve a calcular las estadísticas que el optimizador usa para estimar filas y elegir índices. No borra ni modifica filas y no reconstruye la tabla, aunque puede bloquear brevemente las escrituras sobre ella. Tampoco garantiza que los valores cambien.</p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="accion" value="analizar">
        <input type="hidden" name="tabla" value="<?= e($confirmar) ?>">
        <button type="submit" class="btn-accion">Confirmar y ejecutar</button>
        <a class="btn-secundario" href="mantenimiento.php">Cancelar</a>
    </form>
</section>
<?php endif; ?>

<?php if ($resultado): ?>
<h3 class="seccion-titulo">Resultado de la última operación</h3>
<?php if (empty($resultado['bitacora_ok'])): ?>
    <div class="aviso aviso-error">No se pudo escribir en la bitácora (revise la carpeta de logs).</div>
<?php endif; ?>
<div class="tabla-wrap">
<table class="tabla">
    <tbody>
        <tr><th>Acción</th><td><?= e($resultado['accion']) ?></td></tr>
        <tr><th>Base de datos</th><td><?= e($resultado['base']) ?> (servidor <?= e($resultado['servidor']) ?>)</td></tr>
        <tr><th>Tabla</th><td><?= e($resultado['tabla_log']) ?></td></tr>
        <tr><th>Inicio</th><td><?= e($resultado['inicio']) ?></td></tr>
        <tr><th>Duración</th><td><?= e(number_format($resultado['duracion'], 3, ',', '.')) ?> s</td></tr>
        <tr><th>Resultado</th><td class="<?= $resultado['ok'] ? 'est-ok' : 'est-error' ?>"><?= $resultado['ok'] ? 'OK' : 'ERROR' ?></td></tr>
        <tr><th>Mensaje</th><td><?= e($resultado['mensaje']) ?></td></tr>
    </tbody>
</table>
</div>

<?php if ($resultado['antes'] !== null && $resultado['despues'] !== null): ?>
<h4 class="sub">Contraste antes / después</h4>
<?php
$comparar = [
    ['Filas estimadas (TABLE_ROWS, aproximado)',        'est',     'num'],
    ['Tamaño de datos',                                 'datos',   'tam'],
    ['Tamaño de índices',                               'indices', 'tam'],
    ['Cardinalidad máxima de los índices (estimada)',   'card',    'num'],
    ['Filas exactas (COUNT(*))',                        'exactas', 'num'],
];
?>
<div class="tabla-wrap">
<table class="tabla">
    <thead><tr><th>Valor</th><th class="num">Antes</th><th class="num">Después</th><th>¿Cambió?</th></tr></thead>
    <tbody>
    <?php foreach ($comparar as [$etq, $k, $fmt]): ?>
        <?php
        $a = $resultado['antes'][$k];
        $d = $resultado['despues'][$k];
        $f = $fmt === 'tam' ? 'mant_tam' : 'mant_num';
        ?>
        <tr>
            <td><?= e($etq) ?></td>
            <td class="num"><?= e($f($a)) ?></td>
            <td class="num"><?= e($f($d)) ?></td>
            <td><?= ($a === null || $d === null) ? '—' : ($a === $d ? 'igual' : 'cambió') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<p class="<?= $resultado['conservadas'] ? 'est-ok' : 'est-error' ?>"><?= $resultado['conservadas']
    ? 'Filas conservadas: COUNT(*) devolvió ' . e(monitor_num($resultado['antes']['exactas'])) . ' antes y ' . e(monitor_num($resultado['despues']['exactas'])) . ' después.'
    : 'Atención: el conteo cambió (' . e(monitor_num($resultado['antes']['exactas'])) . ' antes, ' . e(monitor_num($resultado['despues']['exactas'])) . ' después). Revise si otra operación modificó la tabla.' ?></p>
<p class="nota-plan">Actualizar estadísticas no garantiza que los valores cambien: si el motor ya los tenía al día, quedan iguales y la operación sigue siendo correcta.</p>
<?php endif; ?>
<?php endif; ?>

<h3 class="seccion-titulo">Panel de tablas autorizadas</h3>
<div class="tabla-wrap">
<table class="tabla">
    <thead>
        <tr>
            <th>Tabla</th><th>Motor</th>
            <th class="num">Filas (estimado)</th><th class="num">Datos</th>
            <th class="num">Índices (tamaño)</th><th class="num">N.º de índices</th>
            <th>Último ANALYZE (consola)</th><th></th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$panel): ?>
        <tr><td colspan="8">No hay tablas autorizadas en esta base de datos.</td></tr>
    <?php endif; ?>
    <?php foreach ($panel as $t): ?>
        <tr>
            <td><?= e($t['tabla']) ?></td>
            <td><?= $t['motor'] === null ? $nd : e((string) $t['motor']) ?></td>
            <td class="num"><?= $t['est'] === null ? $nd : '≈ ' . e(monitor_num($t['est'])) ?></td>
            <td class="num"><?= $t['datos'] === null ? $nd : e(monitor_tam($t['datos'])) ?></td>
            <td class="num"><?= $t['indices'] === null ? $nd : e(monitor_tam($t['indices'])) ?></td>
            <td class="num"><?= $t['n_indices'] ?></td>
            <td><?= $t['analisis'] === null ? '<span class="no-disp">Sin registro</span>' : e($t['analisis']) ?></td>
            <td><a class="btn-tabla" href="mantenimiento.php?tabla=<?= e(urlencode($t['tabla'])) ?>">Actualizar estadísticas</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<div class="notas">
    <p><strong>Filas (estimado)</strong> es <code>TABLE_ROWS</code>, una aproximación del motor que se recalcula con <code>ANALYZE TABLE</code>. El conteo exacto (<code>COUNT(*)</code>) solo se hace al operar, para contrastar antes y después.</p>
    <p><strong>Último ANALYZE (consola)</strong> sale de la bitácora de este módulo; «Sin registro» significa que la consola nunca ha analizado esa tabla.</p>
</div>

<h3 class="seccion-titulo">Bitácora de mantenimiento (últimas 10 ejecuciones)</h3>
<div class="tabla-wrap">
<table class="tabla">
    <thead>
        <tr><th>Inicio</th><th>Motor</th><th>Base</th><th>Tabla</th><th>Acción</th><th>Resultado</th><th>Detalle</th></tr>
    </thead>
    <tbody>
    <?php if (!$bitacora): ?>
        <tr><td colspan="7">La bitácora está vacía.</td></tr>
    <?php endif; ?>
    <?php foreach (array_slice($bitacora, 0, 10) as $b): ?>
        <tr>
            <td><?= e($b['fecha']) ?></td>
            <td><?= e($b['motor']) ?></td>
            <td><?= e($b['base']) ?></td>
            <td><?= e($b['tabla']) ?></td>
            <td><?= e($b['accion']) ?></td>
            <td class="<?= $b['resultado'] === 'OK' ? 'est-ok' : 'est-error' ?>"><?= e($b['resultado']) ?></td>
            <td><?= e($b['detalle']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<details class="consultas">
    <summary>Consultas SQL utilizadas</summary>
    <?php foreach (MANT_SQL as $nombre => $sql): ?>
        <h4><?= e($nombre) ?></h4>
        <pre><?= e($sql) ?></pre>
    <?php endforeach; ?>
</details>
<?php render_footer();
