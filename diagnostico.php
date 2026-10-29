<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/monitor_lib.php';
require __DIR__ . '/includes/diag_catalogo.php';
require __DIR__ . '/includes/diag_lib.php';
requerir_login();
date_default_timezone_set('America/Costa_Rica');

$cfg = cargar_config();

// POST -> Redirect -> GET. Acciones permitidas: guardar o borrar el plan de
// referencia de un caso del catalogo. Nada de lo enviado llega al SQL.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (string) ($_POST['caso'] ?? '');
    $accion = (string) ($_POST['accion'] ?? '');
    $caso   = diag_caso($id);
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $_SESSION['flash'] = ['error', 'Solicitud no válida. Intente de nuevo.'];
    } elseif ($caso === null) {
        $_SESSION['flash'] = ['error', 'Caso no permitido.'];
        $id = '';
    } elseif ($accion === 'guardar_ref') {
        try {
            $pdo = conectar_bd();
            $con = monitor_conexion($pdo);
            $ok  = diag_ref_guardar($cfg, $id, [
                'caso'     => $id,
                'fecha'    => date('Y-m-d H:i:s T'),
                'cuenta'   => $con['usuario'],
                'base'     => $con['base'],
                'servidor' => $con['version'],
                'sql'      => $caso['sql'],
                'plan'     => diag_explain($pdo, $caso['sql']),
                'indices'  => diag_indices($pdo, diag_tablas($pdo, $caso)),
                'resumen'  => diag_resumen($pdo, $caso['resumen']),
            ]);
            $_SESSION['flash'] = $ok ? ['ok', "Plan actual de $id guardado como referencia (antes)."]
                                     : ['error', 'No se pudo escribir el plan de referencia en la carpeta privada.'];
        } catch (PDOException $ex) {
            $_SESSION['flash'] = ['error', 'No se pudo obtener el plan: ' . $ex->getMessage()];
        }
    } elseif ($accion === 'borrar_ref') {
        $_SESSION['flash'] = diag_ref_borrar($cfg, $id) ? ['ok', "Referencia de $id eliminada."]
                                                        : ['error', 'No se pudo eliminar la referencia.'];
    }
    header('Location: diagnostico.php' . ($id !== '' ? '?caso=' . urlencode($id) : ''));
    exit;
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$catalogo = diag_catalogo();
$id       = (string) ($_GET['caso'] ?? array_key_first($catalogo));
$caso     = diag_caso($id);

$error = null;
if ($caso === null) {
    $error = 'Caso no permitido. Elija uno de los casos del catálogo.';
} else {
    try {
        $pdo     = conectar_bd();
        $con     = monitor_conexion($pdo);
        $plan    = diag_explain($pdo, $caso['sql']);
        $json    = diag_explain_json($pdo, $caso['sql']);
        $tablas  = diag_tablas($pdo, $caso);
        $indices = diag_indices($pdo, $tablas);
        $resumen = diag_resumen($pdo, $caso['resumen']);
        $muestra = diag_muestra($pdo, $caso['sql']);
        $ref     = diag_ref_leer($cfg, $id);
    } catch (PDOException $ex) {
        // El mensaje de PDO no incluye la contraseña (solo usuario y host).
        $error = 'No se pudo consultar la base de datos: ' . $ex->getMessage();
    }
}

/** Tabla HTML generica para filas asociativas (planes, indices, muestras). */
function diag_tabla_html(array $filas): void
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

render_header('Diagnóstico', 'diagnostico');
?>
<h2>Módulo 03 · Diagnóstico de consultas</h2>
<p>Plan de ejecución (<code>EXPLAIN</code>) de las consultas del catálogo, obtenido en vivo desde la base de la cuenta.</p>

<?php if ($flash): ?>
    <div class="aviso aviso-<?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

<nav class="casos" aria-label="Casos del catálogo">
<?php foreach ($catalogo as $cid => $c): ?>
    <a href="diagnostico.php?caso=<?= e($cid) ?>" class="<?= $cid === $id ? 'activo' : '' ?>"><?= e($c['titulo']) ?></a>
<?php endforeach; ?>
</nav>

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

<section class="bloque">
    <h3><?= e($caso['titulo']) ?></h3>
    <p><strong>Propósito:</strong> <?= e($caso['proposito']) ?></p>
    <p><strong>Tablas:</strong> <?= e(implode(', ', $tablas)) ?>
       · <strong>Parámetros:</strong> ninguno; los valores del filtro están fijos en el SQL del servidor.</p>
    <p class="etiqueta-sql">SQL ejecutado</p>
    <pre class="sql"><?= e($caso['sql']) ?></pre>
</section>

<h3 class="seccion-titulo">Plan actual (EXPLAIN)</h3>
<?php diag_tabla_html($plan); ?>
<details class="consultas">
    <summary>Plan en formato JSON (EXPLAIN FORMAT=JSON)</summary>
    <pre><?= e($json) ?></pre>
</details>

<h3 class="seccion-titulo">Interpretación</h3>
<ul class="interpretacion">
<?php foreach (diag_interpretar($plan) as $nota): ?>
    <li><?= e($nota) ?></li>
<?php endforeach; ?>
</ul>
<p class="nota-plan">Las filas de <code>rows</code> son estimaciones del optimizador de MariaDB basadas en las estadísticas de la tabla; no son tiempo ni filas devueltas.</p>

<h3 class="seccion-titulo">Índices de las tablas del caso</h3>
<?php diag_tabla_html($indices); ?>

<h3 class="seccion-titulo">Resultado de la consulta</h3>
<div class="resumen">
<?php foreach ($resumen as $k => $v): ?>
    <div class="dato"><strong><?= e((string) $v) ?></strong><?= e((string) $k) ?></div>
<?php endforeach; ?>
</div>
<details class="consultas">
    <summary>Primeras <?= count($muestra) ?> filas y consulta resumen</summary>
    <?php diag_tabla_html($muestra); ?>
    <h4>Consulta resumen (para comprobar resultados equivalentes)</h4>
    <pre><?= e($caso['resumen']) ?></pre>
</details>

<h3 class="seccion-titulo">Comparación antes / después</h3>
<?php if ($ref === null): ?>
    <div class="bloque">
        <p>Este caso no tiene plan de referencia. Para el ensayo de mejora: guarde aquí el plan actual como «antes», aplique la mejora desde la terminal
           <?= isset($caso['mejora']) ? '(<code>' . e($caso['mejora']['sql']) . '</code>)' : '' ?> y vuelva a esta página para comparar.</p>
        <form method="post" onsubmit="return confirm('¿Guardar el plan actual de <?= e($id) ?> como referencia «antes»?');">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="caso" value="<?= e($id) ?>">
            <input type="hidden" name="accion" value="guardar_ref">
            <button type="submit" class="btn-accion">Guardar plan actual como referencia</button>
        </form>
    </div>
<?php else: ?>
    <?php $igual = diag_resumen_igual($ref['resumen'] ?? [], $resumen); ?>
    <p class="ref-info">Referencia guardada el <strong><?= e((string) $ref['fecha']) ?></strong>
       por <strong><?= e((string) $ref['cuenta']) ?></strong> en la base <strong><?= e((string) $ref['base']) ?></strong>.</p>
    <?php if (($ref['sql'] ?? '') !== $caso['sql']): ?>
        <div class="aviso aviso-error">El SQL del caso cambió desde que se guardó la referencia: la comparación no es válida. Borre la referencia y vuelva a guardarla.</div>
    <?php endif; ?>

    <?php if (isset($caso['mejora'])): ?>
    <div class="bloque">
        <p><strong>Mejora aplicada:</strong> <?= e($caso['mejora']['descripcion']) ?></p>
        <pre class="sql"><?= e($caso['mejora']['sql']) ?></pre>
    </div>
    <?php endif; ?>

    <div class="comparacion">
        <div>
            <h4>Antes (referencia)</h4>
            <?php diag_tabla_html($ref['plan'] ?? []); ?>
            <p class="etiqueta-sql">Índices en ese momento</p>
            <?php diag_tabla_html(array_map(fn($i) => ['tabla' => $i['tabla'], 'indice' => $i['indice'], 'columnas' => $i['columnas']], $ref['indices'] ?? [])); ?>
        </div>
        <div>
            <h4>Después (plan actual)</h4>
            <?php diag_tabla_html($plan); ?>
            <p class="etiqueta-sql">Índices actuales</p>
            <?php diag_tabla_html(array_map(fn($i) => ['tabla' => $i['tabla'], 'indice' => $i['indice'], 'columnas' => $i['columnas']], $indices)); ?>
        </div>
    </div>

    <h4 class="sub">Diferencias</h4>
    <ul class="interpretacion">
    <?php foreach (diag_comparar($ref['plan'] ?? [], $plan) as $nota): ?>
        <li><?= e($nota) ?></li>
    <?php endforeach; ?>
    </ul>

    <h4 class="sub">Resultados equivalentes</h4>
    <div class="tabla-wrap">
    <table class="tabla">
        <thead><tr><th>Valor</th><th class="num">Antes</th><th class="num">Después</th></tr></thead>
        <tbody>
        <?php foreach ($resumen as $k => $v): ?>
            <tr><td><?= e((string) $k) ?></td><td class="num"><?= e((string) ($ref['resumen'][$k] ?? '—')) ?></td><td class="num"><?= e((string) $v) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="<?= $igual ? 'est-ok' : 'est-error' ?>"><?= $igual
        ? 'Mismos resultados: la consulta devuelve los mismos datos antes y después; solo cambió el plan.'
        : 'Los resultados difieren: revise si cambiaron los datos de la tabla desde que se guardó la referencia.' ?></p>

    <form method="post" class="barra-accion" onsubmit="return confirm('¿Borrar la referencia «antes» de <?= e($id) ?>? Solo se elimina el archivo del plan guardado.');">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="caso" value="<?= e($id) ?>">
        <input type="hidden" name="accion" value="borrar_ref">
        <button type="submit" class="btn-secundario">Borrar referencia</button>
    </form>
<?php endif; ?>
<?php render_footer();
