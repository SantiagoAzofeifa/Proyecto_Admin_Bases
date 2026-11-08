<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/monitor_lib.php';
require __DIR__ . '/includes/catalogo_lib.php';
require __DIR__ . '/includes/diag_catalogo.php';
require __DIR__ . '/includes/diag_lib.php';
require __DIR__ . '/includes/lab_catalogo.php';
require __DIR__ . '/includes/lab_ensayo.php';
requerir_login();
date_default_timezone_set('America/Costa_Rica');

$cfg      = cargar_config();
$catalogo = lab_catalogo();

// POST -> Redirect -> GET. Única acción permitida: 'ensayar' un candidato de la
// lista cerrada. Exige token CSRF y el caso y el candidato se validan contra
// lab_catalogo() antes de tocar el motor; nada de lo enviado llega al SQL.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $casoId = is_string($_POST['caso'] ?? null) ? $_POST['caso'] : '';
    $candId = is_string($_POST['candidato'] ?? null) ? $_POST['candidato'] : '';
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $_SESSION['flash'] = ['error', 'Solicitud no válida. Intente de nuevo.'];
    } elseif (($_POST['accion'] ?? '') !== 'ensayar') {
        $_SESSION['flash'] = ['error', 'Acción no permitida.'];
    } elseif (lab_resolver($casoId, $candId) === null) {
        $_SESSION['flash'] = ['error', 'Caso o candidato fuera de la lista permitida: no se ejecutó nada.'];
    } else {
        try {
            $pdo = conectar_bd();
            // Cuando Gabriel entregue lab_solicitar() (bloqueo + respaldo previo + bitácora),
            // se usa esa función; mientras tanto se llama directo al ensayo de Santiago.
            $_SESSION['lab_resultado'] = function_exists('lab_solicitar')
                ? lab_solicitar($pdo, $cfg, $casoId, $candId)
                : lab_ensayar($pdo, $cfg, $casoId, $candId);
        } catch (PDOException $ex) {
            $_SESSION['flash'] = ['error', 'No se pudo conectar a la base de datos.'];
        }
    }
    header('Location: laboratorio.php' . (isset($catalogo[$casoId]) ? '?caso=' . urlencode($casoId) : ''));
    exit;
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$resultado = $_SESSION['lab_resultado'] ?? null;   // se conserva hasta el siguiente ensayo

// Caso elegido (pestañas) y candidato pendiente de confirmar.
$casoId = is_string($_GET['caso'] ?? null) ? $_GET['caso'] : array_key_first($catalogo);
if (!isset($catalogo[$casoId])) {
    $flash  = ['error', 'Caso no permitido. Elija uno de los casos del laboratorio.'];
    $casoId = array_key_first($catalogo);
}
$caso      = $catalogo[$casoId] + ['id' => $casoId];
$tituloDiag = diag_caso($casoId)['titulo'] ?? $casoId;

$error     = null;
$confirmar = null;   // [caso, candidato, filas, dentro_del_limite, motivo]
try {
    $pdo = conectar_bd();
    $con = monitor_conexion($pdo);

    if (isset($_GET['candidato'])) {
        $res = lab_resolver($casoId, is_string($_GET['candidato']) ? $_GET['candidato'] : '');
        if ($res === null) {
            $flash = ['error', 'Candidato fuera de la lista permitida.'];
        } else {
            [$c, $k] = $res;
            [$dentro, $filas, $motivo] = lab_verificar_limite($pdo, $cfg, $c);
            $confirmar = ['caso' => $c, 'cand' => $k, 'filas' => $filas, 'dentro' => $dentro, 'motivo' => $motivo];
        }
    }
} catch (PDOException $ex) {
    // El mensaje de PDO no incluye la contraseña (solo usuario y host).
    $error = 'No se pudo consultar la base de datos: ' . $ex->getMessage();
}

/** Tabla HTML genérica para filas asociativas (planes EXPLAIN). */
function lab_tabla_html(array $filas): void
{
    if (!$filas) {
        echo '<p class="vacio">Sin filas.</p>';
        return;
    }
    echo '<div class="tabla-wrap"><table class="tabla tabla-plan"><thead><tr>';
    foreach (array_keys($filas[0]) as $col) {
        echo '<th>' . e((string) $col) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($filas as $f) {
        echo '<tr>';
        foreach ($f as $v) {
            echo $v === null ? '<td class="nulo">NULL</td>' : '<td>' . e((string) $v) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

render_header('Laboratorio de índices', 'laboratorio');
?>
<h2>Módulo 06 · Laboratorio de índices</h2>
<p>Prueba un índice candidato sobre una <strong>copia temporal</strong> de la tabla (<code>sbx_…</code>) y compara el plan <code>EXPLAIN</code> antes y después. La tabla real nunca se modifica y la copia se elimina al terminar.</p>

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
    <div class="dato"><strong><?= e(monitor_num(lab_max_filas($cfg))) ?></strong>filas máximas a copiar</div>
    <div class="dato"><strong>v<?= e(APP_VERSION) ?></strong>Consola DBA</div>
</div>

<nav class="casos" aria-label="Casos del laboratorio">
<?php foreach ($catalogo as $cid => $c): ?>
    <a href="laboratorio.php?caso=<?= e($cid) ?>" class="<?= $cid === $casoId ? 'activo' : '' ?>"><?= e($cid) ?> · tabla <?= e($c['tabla']) ?></a>
<?php endforeach; ?>
</nav>

<section class="bloque">
    <h3>Caso <?= e($tituloDiag) ?></h3>
    <p><strong>Tabla a copiar:</strong> <?= e($caso['tabla']) ?> · <strong>Consulta:</strong> la misma del Módulo 03 (<?= e($casoId) ?>), apuntando a la copia.</p>
    <p class="etiqueta-sql">Elija el índice candidato que quiere ensayar</p>
    <form method="get" action="laboratorio.php">
        <input type="hidden" name="caso" value="<?= e($casoId) ?>">
        <div class="candidatos">
        <?php foreach ($caso['candidatos'] as $kid => $k): ?>
            <label>
                <input type="radio" name="candidato" value="<?= e($kid) ?>" required<?= ($confirmar && $confirmar['cand']['id'] === $kid) ? ' checked' : '' ?>>
                <strong>Candidato <?= e($kid) ?> · <?= e($k['nombre']) ?></strong>
                <small>Columnas del índice: <?= e(implode(', ', $k['columnas'])) ?></small>
            </label>
        <?php endforeach; ?>
        </div>
        <button type="submit" class="btn-accion">Revisar ensayo</button>
    </form>
</section>

<?php if ($confirmar !== null): ?>
<section class="bloque confirmar">
    <h3>Confirmar ensayo</h3>
    <p><strong>Base de datos:</strong> <?= e($con['base']) ?> · <strong>Tabla:</strong> <?= e($confirmar['caso']['tabla']) ?>
       (<?= e(monitor_num($confirmar['filas'])) ?> filas) · <strong>Candidato:</strong> <?= e($confirmar['cand']['id']) ?> · <?= e($confirmar['cand']['nombre']) ?></p>
<?php if (!$confirmar['dentro']): ?>
    <div class="aviso aviso-error"><?= e($confirmar['motivo']) ?></div>
    <p><a class="btn-secundario" href="laboratorio.php?caso=<?= e($casoId) ?>">Volver</a></p>
<?php else: ?>
    <p>Se ejecutará, en este orden:</p>
    <ol class="lab-pasos">
        <li>Crear la copia <code><?= e(lab_nombre_copia($confirmar['caso']['tabla'])) ?></code> con la misma estructura y hasta <?= e(monitor_num(lab_max_filas($cfg))) ?> filas de la tabla original.</li>
        <li>Mostrar el plan <code>EXPLAIN</code> de la consulta <strong>antes</strong> del índice.</li>
        <li>Crear el índice solo en la copia: <code><?= e(lab_sql_crear_indice($confirmar['caso'], $confirmar['cand'])) ?></code></li>
        <li>Mostrar el plan <strong>después</strong> y compararlos.</li>
        <li>Eliminar la copia (siempre, aunque el ensayo falle).</li>
    </ol>
    <p>La tabla <code><?= e($confirmar['caso']['tabla']) ?></code> no se modifica. Se recomienda generar antes un respaldo en el Módulo 02.</p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="accion" value="ensayar">
        <input type="hidden" name="caso" value="<?= e($confirmar['caso']['id']) ?>">
        <input type="hidden" name="candidato" value="<?= e($confirmar['cand']['id']) ?>">
        <button type="submit" class="btn-accion">Confirmar y ejecutar ensayo</button>
        <a class="btn-secundario" href="laboratorio.php?caso=<?= e($casoId) ?>">Cancelar</a>
    </form>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if ($resultado): ?>
<h3 class="seccion-titulo">Resultado del último ensayo</h3>
<?php if (array_key_exists('bitacora_ok', $resultado) && !$resultado['bitacora_ok']): ?>
    <div class="aviso aviso-error">No se pudo escribir en la bitácora (revise la carpeta de logs).</div>
<?php endif; ?>
<?php if (!empty($resultado['error'])): ?>
    <div class="aviso aviso-error"><?= e($resultado['error']) ?></div>
<?php endif; ?>
<div class="tabla-wrap">
<table class="tabla">
    <tbody>
        <tr><th>Caso · candidato</th><td><?= e((string) $resultado['caso']) ?> · <?= e((string) $resultado['candidato']) ?><?= isset($resultado['indice']) ? ' (' . e($resultado['indice']) . ')' : '' ?></td></tr>
        <tr><th>Base de datos</th><td><?= e($con['base']) ?> (servidor <?= e($con['version']) ?>)</td></tr>
        <tr><th>Tabla original</th><td><?= e((string) ($resultado['tabla'] ?? '—')) ?><?= isset($resultado['filas_origen']) ? ' · ' . e(monitor_num((int) $resultado['filas_origen'])) . ' filas' : '' ?></td></tr>
        <tr><th>Copia</th><td><?= isset($resultado['copia']) ? e($resultado['copia']) . ' · ' . e(monitor_num((int) $resultado['filas_copia'])) . ' filas copiadas' : '—' ?></td></tr>
        <tr><th>Índices heredados quitados de la copia</th><td><?= !empty($resultado['indices_quitados']) ? e(implode(', ', $resultado['indices_quitados'])) : 'ninguno' ?></td></tr>
        <tr><th>Índice creado (solo en la copia)</th><td><code><?= e((string) ($resultado['sql_indice'] ?? '—')) ?></code></td></tr>
        <tr><th>Inicio</th><td><?= e((string) $resultado['inicio']) ?></td></tr>
        <tr><th>Duración</th><td><?= e(number_format(($resultado['duracion_ms'] ?? 0) / 1000, 3, ',', '.')) ?> s</td></tr>
        <tr><th>Resultado</th><td class="<?= $resultado['ok'] ? 'est-ok' : 'est-error' ?>"><?= $resultado['ok'] ? 'OK' : 'ERROR' ?></td></tr>
        <tr><th>Copia eliminada</th><td class="<?= !empty($resultado['copia_eliminada']) ? 'est-ok' : 'est-error' ?>"><?= $resultado['copia_eliminada'] === null ? 'No se llegó a crear' : (!empty($resultado['copia_eliminada']) ? 'Sí, confirmado en information_schema' : 'No: revise y elimine la copia a mano') ?></td></tr>
    </tbody>
</table>
</div>

<?php if (!empty($resultado['ok'])): ?>
<p class="etiqueta-sql">Consulta ensayada (sobre la copia)</p>
<pre class="sql"><?= e((string) $resultado['sql_consulta']) ?></pre>

<div class="comparacion">
    <div>
        <h4>Antes (sin índice secundario)</h4>
        <?php lab_tabla_html($resultado['plan_antes']); ?>
        <ul class="interpretacion">
        <?php foreach ($resultado['interp_antes'] as $nota): ?><li><?= e($nota) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <div>
        <h4>Después (con <?= e(lab_nombre_indice((string) $resultado['candidato'])) ?>)</h4>
        <?php lab_tabla_html($resultado['plan_despues']); ?>
        <ul class="interpretacion">
        <?php foreach ($resultado['interp_despues'] as $nota): ?><li><?= e($nota) ?></li><?php endforeach; ?>
        </ul>
    </div>
</div>

<h4 class="sub">Diferencias</h4>
<ul class="interpretacion">
<?php foreach ($resultado['diferencias'] as $nota): ?><li><?= e($nota) ?></li><?php endforeach; ?>
</ul>
<p class="<?= !empty($resultado['usa_indice']) ? 'est-ok' : 'est-error' ?>"><?= !empty($resultado['usa_indice'])
    ? 'El optimizador usó el índice candidato.'
    : 'El optimizador no usó el índice candidato: para esta consulta ese índice no ayuda.' ?></p>
<p class="<?= !empty($resultado['resumen_igual']) ? 'est-ok' : 'est-error' ?>"><?= !empty($resultado['resumen_igual'])
    ? 'La consulta resumen devolvió los mismos valores antes y después: el índice no cambia el resultado.'
    : 'Atención: la consulta resumen devolvió valores distintos antes y después.' ?></p>
<p class="nota-plan">Las filas de <code>rows</code> son estimaciones del optimizador sobre las estadísticas de la copia; no son tiempo ni filas devueltas.</p>
<?php endif; ?>
<?php endif; ?>
<?php render_footer();
