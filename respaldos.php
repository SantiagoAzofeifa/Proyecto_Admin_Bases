<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/respaldos_lib.php';
requerir_login();
date_default_timezone_set('America/Costa_Rica');

$cfg = cargar_config();

// POST -> Redirect -> GET (evita reenviar con F5)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $_SESSION['flash'] = ['error', 'Solicitud no válida. Intente de nuevo.'];
    } elseif (($_POST['accion'] ?? '') === 'generar') {   // única acción permitida
        [$ok, $salida] = respaldos_generar($cfg);
        $_SESSION['flash'] = $ok ? ['ok', "Respaldo generado: $salida"]
                                 : ['error', $salida];
    }
    header('Location: respaldos.php');
    exit;
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$bitacora  = respaldos_bitacora($cfg);
$respaldos = respaldos_listar($cfg, $bitacora);
$espacio   = array_sum(array_column($respaldos, 'bytes'));

render_header('Respaldos', 'respaldos');
?>
<h2>Módulo 02 · Respaldos</h2>
<p>Genere respaldos comprimidos de <strong><?= e((string) $cfg['DB_NAME']) ?></strong> y consulte la bitácora de ejecución.</p>

<?php if ($flash): ?>
    <div class="aviso aviso-<?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

<form method="post" class="barra-accion">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="accion" value="generar">
    <button type="submit" class="btn-accion">Generar nuevo respaldo</button>
</form>

<div class="resumen">
    <div class="dato"><strong><?= count($respaldos) ?></strong> respaldos</div>
    <div class="dato"><strong><?= e(respaldos_tam((int) $espacio)) ?></strong> espacio total</div>
    <div class="dato"><strong><?= $respaldos ? e(date('d/m/Y H:i:s', $respaldos[0]['fecha'])) : '—' ?></strong> más reciente</div>
</div>

<h3 class="seccion-titulo">Respaldos disponibles</h3>
<div class="tabla-wrap">
<table class="tabla">
    <thead><tr><th>Archivo</th><th>Fecha</th><th>Tamaño</th><th>Estado</th></tr></thead>
    <tbody>
    <?php if (!$respaldos): ?>
        <tr><td colspan="4">Aún no hay respaldos.</td></tr>
    <?php endif; ?>
    <?php foreach ($respaldos as $r): ?>
        <tr>
            <td><?= e($r['archivo']) ?></td>
            <td><?= e(date('d/m/Y H:i:s', $r['fecha'])) ?></td>
            <td><?= e(respaldos_tam($r['bytes'])) ?></td>
            <td class="est-<?= strtolower(e($r['estado'])) ?>"><?= e($r['estado']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<h3 class="seccion-titulo">Bitácora (últimas 15 ejecuciones)</h3>
<div class="tabla-wrap">
<table class="tabla">
    <thead><tr><th>Fecha/hora</th><th>Archivo</th><th>Resultado</th><th>Detalle</th></tr></thead>
    <tbody>
    <?php if (!$bitacora): ?>
        <tr><td colspan="4">La bitácora está vacía.</td></tr>
    <?php endif; ?>
    <?php foreach (array_slice($bitacora, 0, 15) as $b): ?>
        <tr>
            <td><?= e($b['fecha']) ?></td>
            <td><?= e($b['archivo']) ?></td>
            <td class="est-<?= strtolower(e($b['estado'])) ?>"><?= e($b['estado']) ?></td>
            <td><?= e($b['detalle']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php render_footer();
