<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/monitor_lib.php';
require __DIR__ . '/includes/catalogo_lib.php';
require __DIR__ . '/includes/cartografo_lib.php';
requerir_login();
date_default_timezone_set('America/Costa_Rica');

$cfg = cargar_config();

// Módulo de solo lectura: no hay POST. La única entrada es ?tabla=, que se
// acepta solo si coincide con una tabla propia de la base (cat_tablas()).
$error = null;
$aviso = null;
$foco  = null;
try {
    $pdo = conectar_bd();
    $con = monitor_conexion($pdo);

    if (isset($_GET['tabla']) && $_GET['tabla'] !== '') {
        $pedida = is_string($_GET['tabla']) ? $_GET['tabla'] : '';
        if (cat_tabla_existe($pdo, $pedida)) {
            $foco = $pedida;
        } else {
            $aviso = 'La tabla indicada no existe o no está permitida en esta base. Se muestran todas las tablas.';
        }
    }

    $todasTablas = cat_tablas($pdo);
    $mapa        = carto_analizar($pdo, null);          // el mapa siempre muestra el conjunto completo
    $relVista    = $foco === null ? $mapa['relaciones']
                 : array_values(array_filter($mapa['relaciones'], fn($r) => $r['hijo'] === $foco || $r['padre'] === $foco));
    [$svg, $fuera] = carto_svg($todasTablas, $mapa['relaciones'], $foco);

    // Bitácora (parte de Gabriel): se registra solo si su función ya existe en el repositorio.
    if (function_exists('cartografo_registrar')) {
        cartografo_registrar($cfg, [
            'inicio'     => date('Y-m-d H:i:s P'),
            'base'       => $con['base'],
            'tabla'      => $foco ?? '(todas)',
            'relaciones' => count($mapa['relaciones']),
            'huerfanos'  => $mapa['total_huerfanos'],
            'ok'         => true,
        ]);
    }
} catch (PDOException $ex) {
    // El mensaje de PDO no incluye la contraseña (solo usuario y host).
    $error = 'No se pudo consultar la base de datos: ' . $ex->getMessage();
}

$nd = '<span class="no-disp">No disponible</span>';

render_header('Cartógrafo de relaciones', 'cartografo');
?>
<h2>Módulo 05 · Cartógrafo de relaciones</h2>
<p>Mapa de las relaciones entre las tablas de la base: las declaradas con <code>FOREIGN KEY</code> y las que se infieren por nombre y tipo de columna. Es una consulta de solo lectura sobre <code>information_schema</code>.</p>

<?php if ($error !== null): ?>
    <div class="aviso aviso-error"><?= e($error) ?></div>
<?php render_footer(); exit; endif; ?>

<?php if ($aviso): ?>
    <div class="aviso aviso-error"><?= e($aviso) ?></div>
<?php endif; ?>

<div class="resumen">
    <div class="dato"><strong><?= e((string) $cfg['DB_MOTOR']) ?></strong>motor · servidor <?= e($con['version']) ?></div>
    <div class="dato"><strong><?= e($con['base']) ?></strong>base de datos</div>
    <div class="dato"><strong><?= e($con['usuario']) ?></strong>usuario conectado</div>
    <div class="dato"><strong><?= count($todasTablas) ?></strong>tablas propias</div>
    <div class="dato"><strong><?= count($mapa['relaciones']) ?></strong>relaciones encontradas</div>
    <div class="dato"><strong class="<?= $mapa['total_huerfanos'] > 0 ? 'est-error' : '' ?>"><?= e(monitor_num($mapa['total_huerfanos'])) ?></strong>filas huérfanas</div>
</div>

<?php if (!$todasTablas): ?>
    <div class="aviso aviso-error">La base no tiene tablas propias: no hay relaciones que mostrar.</div>
<?php render_footer(); exit; endif; ?>

<form method="get" class="barra-filtro">
    <label for="tabla">Resaltar una tabla</label>
    <select name="tabla" id="tabla">
        <option value="">Todas las tablas</option>
        <?php foreach ($todasTablas as $t): ?>
            <option value="<?= e($t) ?>"<?= $t === $foco ? ' selected' : '' ?>><?= e($t) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-accion">Ver</button>
    <?php if ($foco !== null): ?><a class="btn-secundario" href="cartografo.php">Quitar filtro</a><?php endif; ?>
</form>

<h3 class="seccion-titulo">Mapa de relaciones</h3>
<div class="mapa-wrap"><?= $svg /* generado por carto_svg(): todo texto ya pasó por e() */ ?></div>
<ul class="leyenda-mapa">
    <li><span class="lg lg-decl"></span> Declarada (FOREIGN KEY)</li>
    <li><span class="lg lg-inf"></span> Inferida por nombre y tipo</li>
    <li><span class="lg lg-huer"></span> Con filas huérfanas</li>
    <li>La flecha va de la tabla hija a la tabla padre.</li>
</ul>
<?php if ($fuera > 0): ?>
    <div class="aviso aviso-error">El mapa dibuja como máximo <?= CARTO_MAX_TABLAS ?> tablas; <?= $fuera ?> no se dibujaron, pero sí aparecen en la tabla de abajo.</div>
<?php endif; ?>

<h3 class="seccion-titulo">Relaciones<?= $foco !== null ? ' de ' . e($foco) : '' ?></h3>
<?php if (!$relVista): ?>
    <p class="vacio">No se encontraron relaciones<?= $foco !== null ? ' para esta tabla' : '' ?>.</p>
<?php else: ?>
<div class="tabla-wrap">
<table class="tabla">
    <thead><tr>
        <th>Tabla hija</th><th>Columna</th><th>Tabla padre</th><th>Columna</th>
        <th>Origen</th><th>Confianza</th><th class="num">Huérfanos</th><th>Detalle</th>
    </tr></thead>
    <tbody>
    <?php foreach ($relVista as $r): ?>
        <tr>
            <td><?= e($r['hijo']) ?></td>
            <td><?= e($r['col_hijo']) ?></td>
            <td><?= e($r['padre']) ?></td>
            <td><?= e($r['col_padre']) ?></td>
            <td><?= e($r['origen']) ?></td>
            <td><?= $r['confianza'] !== '' ? e($r['confianza']) : '—' ?></td>
            <td class="num <?= ($r['huerfanos'] ?? 0) > 0 ? 'est-error' : '' ?>">
                <?= $r['huerfanos'] === null ? $nd : e(monitor_num($r['huerfanos'])) ?>
            </td>
            <td><?= e($r['motivo']) ?>
                <?php if ($r['muestra']): ?>
                    <br><strong>Valores sin padre (muestra):</strong> <?= e(implode(', ', array_map('strval', $r['muestra']))) ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<p class="nota-plan">Una relación inferida es solo una propuesta basada en nombres y tipos; el conteo de huérfanos (filas del hijo cuyo valor no existe en el padre) ayuda a confirmarla. Cero huérfanos no prueba que la relación sea real.</p>
<?php endif; ?>

<?php if ($mapa['descartadas']): ?>
<details class="consultas">
    <summary>Coincidencias de nombre descartadas por tipo (<?= count($mapa['descartadas']) ?>)</summary>
    <ul class="interpretacion">
    <?php foreach ($mapa['descartadas'] as $d): ?>
        <li><strong><?= e($d['hijo'] . '.' . $d['col_hijo']) ?> → <?= e($d['padre'] . '.' . $d['col_padre']) ?></strong>: <?= e($d['motivo']) ?></li>
    <?php endforeach; ?>
    </ul>
</details>
<?php endif; ?>
<?php render_footer();
