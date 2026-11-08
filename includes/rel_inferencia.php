<?php
// Inferencia de relaciones no declaradas (Módulo 05 · Cartógrafo de relaciones).
// Santiago Azofeifa. Trabaja sobre lo que ya leyó catalogo_lib.php (Karen):
// no consulta la base, así que se prueba sin conexión con un catálogo de ejemplo.
//
// Una relación inferida es solo una PROPUESTA: la confirma o desmiente el
// conteo de huérfanos. Por eso cada una lleva la regla que la generó, la
// confianza y el motivo, para que la pantalla pueda explicarla.

// Nombres de llave primaria demasiado genéricos para compararlos tal cual:
// con ellos solo se aceptan patrones como cliente_id o id_cliente.
const REL_PK_GENERICAS = ['id', 'codigo', 'code'];

/**
 * Normaliza un COLUMN_TYPE de MariaDB ("int(10) unsigned", "varchar(60)",
 * "decimal(10,2)") en familia, tipo base y signo.
 */
function rel_tipo(string $columnType): array
{
    $t = strtolower(trim($columnType));
    preg_match('/^([a-z]+)/', $t, $m);
    $base = $m[1] ?? $t;
    if ($base === 'integer') {
        $base = 'int';
    }
    $familias = [
        'entero'   => ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'],
        'decimal'  => ['decimal', 'numeric'],
        'flotante' => ['float', 'double', 'real'],
        'texto'    => ['char', 'varchar'],
        'fecha'    => ['date', 'datetime', 'timestamp', 'time', 'year'],
        'binario'  => ['binary', 'varbinary'],
    ];
    $familia = 'otro';
    foreach ($familias as $f => $tipos) {
        if (in_array($base, $tipos, true)) {
            $familia = $f;
            break;
        }
    }
    return ['familia' => $familia, 'base' => $base, 'unsigned' => strpos($t, 'unsigned') !== false];
}

/**
 * ¿Pueden la columna hija y la llave del padre guardar los mismos valores?
 * Devuelve [compatible, exacto, explicacion].
 * - Mismo tipo base y mismo signo: compatible y exacto.
 * - Enteros de distinto tamaño con el mismo signo: compatible, no exacto.
 * - Distinta familia o distinto signo: incompatible (MariaDB tampoco
 *   aceptaría esa FOREIGN KEY).
 */
function rel_tipos_compatibles(string $tipoHijo, string $tipoPadre): array
{
    $h = rel_tipo($tipoHijo);
    $p = rel_tipo($tipoPadre);
    if ($h['familia'] !== $p['familia'] || $h['familia'] === 'otro') {
        return [false, false, "tipos de distinta familia ($tipoHijo frente a $tipoPadre)"];
    }
    if ($h['familia'] === 'entero' && $h['unsigned'] !== $p['unsigned']) {
        return [false, false, "distinto signo ($tipoHijo frente a $tipoPadre)"];
    }
    if ($h['base'] === $p['base']) {
        return [true, true, "mismo tipo ($tipoHijo)"];
    }
    return [true, false, "tipos compatibles de distinto tamaño ($tipoHijo frente a $tipoPadre)"];
}

/** Variantes de la raíz de un nombre: "clientes" -> clientes, cliente. */
function rel_raices(string $nombre): array
{
    $n = strtolower($nombre);
    $r = [$n];
    if (substr($n, -2) === 'es' && strlen($n) > 3) {
        $r[] = substr($n, 0, -2);
    }
    if (substr($n, -1) === 's' && strlen($n) > 2) {
        $r[] = substr($n, 0, -1);
    }
    return array_values(array_unique($r));
}

/**
 * Regla de nombre que relaciona la columna hija con la llave del padre, o null.
 * - nombre_exacto: la columna se llama igual que la llave primaria del padre
 *   (diag_pedidos.id_cliente -> clientes.id_cliente).
 * - patron: la llave del padre es genérica (id) o se llama id_X, y la columna
 *   es X_id, id_X o Xid, con X tomado del nombre de la tabla padre
 *   (pedidos.cliente_id -> clientes.id).
 */
function rel_regla_nombre(string $col, string $padre, string $pkPadre): ?string
{
    $col = strtolower($col);
    $pk  = strtolower($pkPadre);
    if ($col === $pk && !in_array($pk, REL_PK_GENERICAS, true)) {
        return 'nombre_exacto';
    }
    $raices = rel_raices($padre);
    if (preg_match('/^id_(.+)$|^(.+)_id$/', $pk, $m)) {
        $raices = array_merge($raices, rel_raices($m[1] !== '' ? $m[1] : $m[2]));
    }
    foreach (array_unique($raices) as $x) {
        if (in_array($col, [$x . '_id', 'id_' . $x, $x . 'id'], true) && $col !== $pk) {
            return 'patron';
        }
    }
    return null;
}

/**
 * Inferencia completa. Recibe la salida de cat_columnas(), cat_llaves_primarias()
 * y cat_relaciones_declaradas(). Devuelve:
 *   'relaciones'  => propuestas aceptadas, con regla, confianza y motivo;
 *   'descartadas' => coincidencias de nombre rechazadas por el tipo (para explicarlas).
 * Reglas fijas:
 * - Solo padres con llave primaria de UNA columna.
 * - No se proponen columnas ya declaradas como FOREIGN KEY.
 * - La columna no puede ser por sí sola la llave primaria de su tabla
 *   (sí puede ser parte de una llave compuesta, como en una tabla de detalle).
 * - Se ignoran las copias temporales sbx_ del laboratorio y la tabla consigo misma.
 * - Si una columna encaja con varios padres, todas se marcan como ambiguas.
 */
function rel_inferir_detalle(array $columnas, array $pks, array $declaradas): array
{
    $prefijo = defined('CAT_PREFIJO_SBX') ? CAT_PREFIJO_SBX : 'sbx_';
    $esCopia = fn(string $t) => strpos($t, $prefijo) === 0;

    $yaDeclarada = [];
    foreach ($declaradas as $d) {
        $yaDeclarada[$d['hijo'] . '.' . $d['col_hijo']] = true;
    }
    $tipoDe = [];
    foreach ($columnas as $t => $cols) {
        foreach ($cols as $c) {
            $tipoDe[$t][$c['col']] = $c['completo'] ?? $c['tipo'];
        }
    }

    $aceptadas = [];
    $descartadas = [];
    foreach ($columnas as $hijo => $cols) {
        if ($esCopia($hijo)) {
            continue;
        }
        $pkHijo = $pks[$hijo] ?? [];
        foreach ($cols as $c) {
            $col = $c['col'];
            if (isset($yaDeclarada["$hijo.$col"]) || $pkHijo === [$col]) {
                continue;
            }
            foreach ($pks as $padre => $pk) {
                if ($padre === $hijo || $esCopia($padre) || count($pk) !== 1) {
                    continue;
                }
                $regla = rel_regla_nombre($col, $padre, $pk[0]);
                if ($regla === null) {
                    continue;
                }
                $tH = $tipoDe[$hijo][$col];
                $tP = $tipoDe[$padre][$pk[0]] ?? '';
                [$ok, $exacto, $explicacion] = rel_tipos_compatibles($tH, $tP);
                $rel = ['hijo' => $hijo, 'col_hijo' => $col, 'padre' => $padre, 'col_padre' => $pk[0],
                        'regla' => $regla, 'tipo_hijo' => $tH, 'tipo_padre' => $tP];
                if (!$ok) {
                    $descartadas[] = $rel + ['motivo' => 'El nombre coincide, pero hay ' . $explicacion . '.'];
                    continue;
                }
                $rel['confianza'] = ($regla === 'nombre_exacto' && $exacto) ? 'alta' : 'media';
                $rel['motivo'] = ($regla === 'nombre_exacto'
                        ? "Se llama igual que la llave primaria de $padre"
                        : "Sigue el patrón de nombre de la llave de $padre")
                    . " y tiene $explicacion.";
                $aceptadas[] = $rel;
            }
        }
    }

    // Una misma columna con varios padres posibles: se avisa en todas.
    $cuenta = array_count_values(array_map(fn($r) => $r['hijo'] . '.' . $r['col_hijo'], $aceptadas));
    foreach ($aceptadas as &$r) {
        if ($cuenta[$r['hijo'] . '.' . $r['col_hijo']] > 1) {
            $r['confianza'] = 'baja';
            $r['motivo'] .= ' Ambigua: la columna también encaja con otra tabla.';
        }
    }
    unset($r);

    return ['relaciones' => $aceptadas, 'descartadas' => $descartadas];
}

/** Solo las relaciones aceptadas (es lo que usa cat_inferir()). */
function rel_inferir(array $columnas, array $pks, array $declaradas): array
{
    return rel_inferir_detalle($columnas, $pks, $declaradas)['relaciones'];
}
