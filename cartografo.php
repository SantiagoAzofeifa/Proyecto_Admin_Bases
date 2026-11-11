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
// acepta solo si coincide EXACTAMENTE con una tabla propia de la base.
// Cada análisis (correcto, con advertencia o con error) queda en la bitácora
// privada ~/dba_logs/cartografo.log (includes/cartografo_control.php).
$error        = null;
$aviso        = null;
$foco         = null;
$bitacora_ok  = true;
$t0           = microtime(true);
$inicio       = date('Y-m-d H:i:s P');
try {
    $pdo = conectar_bd();
    $con = monitor_conexion($pdo);
    $todasTablas = cat_tablas($pdo);

    if (isset($_GET['tabla']) && $_GET['tabla'] !== '') {
        [$foco, $motivo] = carto_validar_tabla($todasTablas, $_GET['tabla']);
        if ($foco === null) {
            $aviso = 'ERROR: ' . $motivo . ' Se muestran todas las tablas.';
            $pedida = is_string($_GET['tabla']) ? $_GET['tabla'] : '(' . gettype($_GET['tabla']) . ')';
            $bitacora_ok = cartografo_registrar($cfg, [
                'inicio' => $inicio, 'base' => $con['base'], 'accion' => 'Solicitud rechazada',
                'tabla'  => '(no válida) ' . $pedida, 'ok' => false, 'mensaje' => $motivo,
            ]) && $bitacora_ok;
        }
    }

    $mapa     = carto_analizar($pdo, null);          // el mapa siempre muestra el conjunto completo
    // Se conservan los índices de $mapa['relaciones']: el tablero interactivo los usa como identificador.
    $relVista = $foco === null ? $mapa['relaciones']
              : array_filter($mapa['relaciones'], fn($r) => $r['hijo'] === $foco || $r['padre'] === $foco);
    [$svg, $fuera] = carto_svg($todasTablas, $mapa['relaciones'], $foco);   // respaldo sin JavaScript

    // Datos del tablero interactivo (js/cartografo.js): columnas, llaves y filas estimadas
    // de cada tabla propia, y cada relación con la consulta que cuenta sus huérfanos.
    $columnas = cat_columnas($pdo);
    $filasEst = carto_filas_estimadas($pdo);

    $bitacora_ok = cartografo_registrar($cfg, [
        'inicio'     => $inicio,
        'base'       => $con['base'],
        'tabla'      => $foco ?? '(todas)',
        'relaciones' => count($mapa['relaciones']),
        'huerfanos'  => $mapa['total_huerfanos'],
        'omitidas'   => $mapa['omitidas'],
        'duracion'   => microtime(true) - $t0,
        'ok'         => true,
    ]) && $bitacora_ok;
} catch (PDOException $ex) {
    // El mensaje de PDO no incluye la contraseña (solo usuario y host).
    $error = 'No se pudo consultar la base de datos: ' . $ex->getMessage();
    $bitacora_ok = cartografo_registrar($cfg, [
        'inicio' => $inicio, 'base' => (string) $cfg['DB_NAME'], 'ok' => false,
        'mensaje' => 'Error de base de datos (' . ($ex->errorInfo[1] ?? '?') . '): ' . substr($ex->getMessage(), 0, 150),
        'duracion' => microtime(true) - $t0,
    ]);
}

$nd = '<span class="no-disp">No disponible</span>';

// Datos del tablero en JSON. Las banderas JSON_HEX_* convierten <, >, & y comillas
// en secuencias \uXXXX: ningún nombre de tabla puede cerrar la etiqueta <script>.
$datosTablero = null;
if ($error === null && $todasTablas) {
    $nDecl = count(array_filter($mapa['relaciones'], fn($r) => $r['origen'] === 'declarada'));
    $tablasJs = [];
    foreach ($todasTablas as $t) {
        $tablasJs[] = [
            'nombre'   => $t,
            'filas'    => $filasEst[$t] ?? null,
            'columnas' => array_map(fn($c) => ['n' => $c['col'], 't' => $c['completo'], 'k' => $c['llave']], $columnas[$t] ?? []),
        ];
    }
    $relsJs = [];
    foreach ($mapa['relaciones'] as $i => $r) {
        $relsJs[] = [
            'id' => $i, 'hijo' => $r['hijo'], 'colHijo' => $r['col_hijo'], 'padre' => $r['padre'], 'colPadre' => $r['col_padre'],
            'origen' => $r['origen'], 'regla' => $r['regla'] ?? '', 'confianza' => $r['confianza'],
            'huerfanos' => $r['huerfanos'], 'estado' => $r['estado'], 'motivo' => $r['motivo'],
            'muestra' => array_map('strval', $r['muestra']),
            'sql' => cat_sql_huerfanos($r['hijo'], $r['col_hijo'], $r['padre'], $r['col_padre']),
        ];
    }
    $datosTablero = json_encode(
        ['base' => $con['base'], 'foco' => $foco, 'tablas' => $tablasJs, 'relaciones' => $relsJs],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
    );
}

render_header('Cartógrafo de relaciones', 'cartografo');
?>
<?php titulo_modulo('05', 'Cartógrafo de relaciones'); ?>
<p>Cómo se relacionan las tablas de la base: las relaciones declaradas con <code>FOREIGN KEY</code> y las que se deducen por nombre y tipo de columna, cada una comprobada contando sus filas huérfanas. Solo lee <code>information_schema</code> y las tablas propias.</p>

<?php if ($error !== null): ?>
    <div class="aviso aviso-error"><?= e($error) ?></div>
<?php render_footer(); exit; endif; ?>

<?php if ($aviso): ?>
    <div class="aviso aviso-error"><?= e($aviso) ?></div>
<?php endif; ?>
<?php if (!$bitacora_ok): ?>
    <div class="aviso aviso-error">No se pudo escribir en la bitácora (revise la carpeta de logs).</div>
<?php endif; ?>
<?php if ($mapa['omitidas'] > 0): ?>
    <div class="aviso aviso-error"><?= (int) $mapa['omitidas'] ?> relación(es) quedaron sin conteo de huérfanos por los límites del módulo (detalle en la última columna).</div>
<?php endif; ?>

<div class="resumen">
    <div class="dato"><strong><?= e((string) $cfg['DB_MOTOR']) ?></strong>servidor <?= e($con['version']) ?></div>
    <div class="dato"><strong class="ident"><?= e($con['base']) ?></strong>base de datos</div>
    <div class="dato"><strong class="ident"><?= e($con['usuario']) ?></strong>usuario conectado</div>
    <div class="dato"><strong><?= count($todasTablas) ?></strong>tablas propias</div>
    <div class="dato"><strong><?= count($mapa['relaciones']) ?></strong><?= $nDecl ?? 0 ?> declaradas y <?= count($mapa['relaciones']) - ($nDecl ?? 0) ?> inferidas</div>
    <div class="dato"><strong class="<?= $mapa['total_huerfanos'] > 0 ? 'est-error' : 'est-ok' ?>"><?= e(monitor_num($mapa['total_huerfanos'])) ?></strong>filas huérfanas</div>
</div>

<?php if (!$todasTablas): ?>
    <div class="aviso aviso-error">La base no tiene tablas propias: no hay relaciones que mostrar.</div>
<?php render_footer(); exit; endif; ?>

<section class="tablero" id="tablero" aria-label="Mapa de relaciones">
    <div class="tablero-barra">
        <label class="tablero-buscar">
            <span class="sr">Buscar una tabla en el mapa</span>
            <input type="search" id="tablero-buscar" placeholder="Buscar tabla" autocomplete="off" spellcheck="false">
        </label>
        <div class="tablero-filtros" role="group" aria-label="Qué relaciones mostrar">
            <button type="button" class="chip" data-filtro="decl" aria-pressed="true"><span class="chip-muestra chip-decl" aria-hidden="true"></span>Declaradas</button>
            <button type="button" class="chip" data-filtro="inf" aria-pressed="true"><span class="chip-muestra chip-inf" aria-hidden="true"></span>Inferidas</button>
            <button type="button" class="chip" data-filtro="huer" aria-pressed="false"><span class="chip-muestra chip-huer" aria-hidden="true"></span>Solo con huérfanos</button>
        </div>
        <div class="tablero-vista" role="group" aria-label="Vista del mapa">
            <button type="button" class="ico" data-accion="alejar" title="Alejar" aria-label="Alejar">&minus;</button>
            <button type="button" class="ico" data-accion="acercar" title="Acercar" aria-label="Acercar">+</button>
            <button type="button" class="txt" data-accion="encuadrar">Encuadrar</button>
            <button type="button" class="txt" data-accion="reordenar" title="Volver a la disposición automática">Reordenar</button>
            <button type="button" class="txt" data-accion="completa">Pantalla completa</button>
        </div>
    </div>
    <div class="tablero-cuerpo">
        <div class="tablero-lienzo" id="tablero-lienzo">
            <!-- Respaldo sin JavaScript: el mapa generado en el servidor. -->
            <div class="mapa-wrap"><?= $svg /* generado por carto_svg(): todo texto ya pasó por e() */ ?></div>
            <p class="tablero-ayuda">Arrastra las tablas para ordenarlas y el fondo para moverte. Ctrl + rueda acerca o aleja.</p>
        </div>
        <aside class="tablero-panel" id="tablero-panel" aria-live="polite">
            <h3>Explora el mapa</h3>
            <p>Pasa el cursor sobre una tabla para ver con quién se relaciona. Haz clic en una tabla o en una línea para ver su detalle y la consulta que comprueba la relación.</p>
        </aside>
    </div>
    <ul class="tablero-leyenda">
        <li><span class="lg lg-decl"></span>Declarada con FOREIGN KEY</li>
        <li><span class="lg lg-inf"></span><span class="estrella" aria-hidden="true"></span>Inferida por nombre y tipo</li>
        <li><span class="lg lg-huer"></span>Tiene filas huérfanas</li>
        <li><span class="llave llave-pk">PK</span> llave primaria <span class="llave llave-fk">FK</span> columna que apunta a otra tabla</li>
        <li>La flecha va de la tabla hija a la tabla padre</li>
    </ul>
    <?php if ($datosTablero !== null): ?>
    <script type="application/json" id="carto-datos"><?= $datosTablero ?></script>
    <?php endif; ?>
</section>
<script src="js/cartografo.js" defer></script>
<?php if ($fuera > 0): ?>
    <p class="vacio">Sin JavaScript el mapa dibuja como máximo <?= CARTO_MAX_TABLAS ?> tablas; <?= $fuera ?> no se dibujaron, pero sí aparecen en la tabla de abajo.</p>
<?php endif; ?>

<form method="get" class="barra-filtro">
    <label for="tabla">Analizar solo una tabla</label>
    <select name="tabla" id="tabla">
        <option value="">Todas las tablas</option>
        <?php foreach ($todasTablas as $t): ?>
            <option value="<?= e($t) ?>"<?= $t === $foco ? ' selected' : '' ?>><?= e($t) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-accion">Analizar</button>
    <?php if ($foco !== null): ?><a class="btn-secundario" href="cartografo.php">Ver todas</a><?php endif; ?>
</form>

<h3 class="seccion-titulo">Relaciones<?= $foco !== null ? ' de ' . e($foco) : '' ?></h3>
<?php if (!$relVista): ?>
    <p class="vacio">No se encontraron relaciones<?= $foco !== null ? ' para esta tabla' : '' ?>.</p>
<?php else: ?>
<div class="tabla-wrap">
<table class="tabla tabla-rel">
    <thead><tr>
        <th>Tabla hija</th><th>Columna</th><th>Tabla padre</th><th>Columna</th>
        <th>Origen</th><th>Confianza</th><th class="num">Huérfanos</th><th>Estado</th><th>Detalle</th><th><span class="sr">Mapa</span></th>
    </tr></thead>
    <tbody>
    <?php foreach ($relVista as $i => $r): ?>
        <tr data-rel="<?= (int) $i ?>">
            <td class="ident"><?= e($r['hijo']) ?></td>
            <td class="ident"><?= e($r['col_hijo']) ?></td>
            <td class="ident"><?= e($r['padre']) ?></td>
            <td class="ident"><?= e($r['col_padre']) ?></td>
            <td><?= e($r['origen']) ?></td>
            <td><?= $r['confianza'] !== '' ? e($r['confianza']) : '—' ?></td>
            <td class="num <?= ($r['huerfanos'] ?? 0) > 0 ? 'est-error' : '' ?>">
                <?= $r['huerfanos'] === null ? $nd : e(monitor_num($r['huerfanos'])) ?>
            </td>
            <td class="<?= $r['estado'] === 'ADVERTENCIA' ? 'est-error' : ($r['estado'] === 'OK' ? 'est-ok' : '') ?>"><?= e($r['estado']) ?></td>
            <td><?= e($r['motivo']) ?>
                <?php if ($r['muestra']): ?>
                    <br><strong>Valores sin padre (muestra):</strong> <?= e(implode(', ', array_map('strval', $r['muestra']))) ?>
                <?php endif; ?>
            </td>
            <td><button type="button" class="btn-tabla ver-rel" data-rel="<?= (int) $i ?>" hidden>Ver en el mapa</button></td>
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

<?php $ultimos = cartografo_bitacora($cfg, 10); ?>
<h3 class="seccion-titulo">Bitácora (últimos 10 análisis)</h3>
<div class="tabla-wrap">
<table class="tabla">
    <thead><tr><th>Fecha/hora</th><th>Tabla</th><th>Acción</th><th>Resultado</th><th>Detalle</th></tr></thead>
    <tbody>
    <?php if (!$ultimos): ?>
        <tr><td colspan="5">La bitácora está vacía.</td></tr>
    <?php endif; ?>
    <?php foreach ($ultimos as $b): ?>
        <tr>
            <td><?= e($b['fecha']) ?></td>
            <td><?= e($b['tabla']) ?></td>
            <td><?= e($b['accion']) ?></td>
            <td class="<?= $b['resultado'] === 'OK' ? 'est-ok' : 'est-error' ?>"><?= e($b['resultado']) ?></td>
            <td><?= e($b['detalle']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php render_footer();
