<?php
// Lógica del Módulo 05 (Cartógrafo de relaciones). La vista vive en cartografo.php.
// Parte de Andy Luna: arma los datos de la pantalla y dibuja el mapa SVG.
// Solo LEE: usa el lector del catálogo de Karen (catalogo_lib.php) y las reglas
// de inferencia de Santiago (rel_inferencia.php). No modifica ninguna tabla.
//
// Nada que escriba el usuario llega al SQL: la única entrada es el nombre de
// una tabla (?tabla=), que se acepta solo si está en cat_tablas().

const CARTO_MAX_TABLAS = 20;    // tope de cajas en el mapa SVG

/**
 * Reúne todo lo que muestra la pantalla.
 *
 * @param string|null $foco tabla a resaltar (ya validada con cat_tabla_existe), o null para todas
 * @return array{tablas:array,relaciones:array,descartadas:array,total_huerfanos:int}
 */
function carto_analizar(PDO $pdo, ?string $foco = null): array
{
    $tablas   = cat_tablas($pdo);
    $columnas = cat_columnas($pdo);
    $pks      = cat_llaves_primarias($pdo);
    $decl     = cat_relaciones_declaradas($pdo);
    $det      = rel_inferir_detalle($columnas, $pks, $decl);

    // Declaradas (FOREIGN KEY) + inferidas, siempre entre tablas propias.
    $todas = [];
    foreach ($decl as $r) {
        $todas[] = $r + ['origen' => 'declarada', 'confianza' => '', 'motivo' => 'FOREIGN KEY declarada en la base.'];
    }
    foreach ($det['relaciones'] as $r) {
        $todas[] = $r + ['origen' => 'inferida'];
    }

    $relaciones = [];
    $totalHuerfanos = 0;
    foreach ($todas as $r) {
        if (!in_array($r['hijo'], $tablas, true) || !in_array($r['padre'], $tablas, true)) {
            continue;   // vistas o copias sbx_ del laboratorio
        }
        if ($foco !== null && $r['hijo'] !== $foco && $r['padre'] !== $foco) {
            continue;
        }
        // Conteo de huérfanos de la relación (la relación salió del catálogo, no del usuario).
        $r['huerfanos'] = null;
        $r['muestra']   = [];
        try {
            $r['huerfanos'] = cat_contar_huerfanos($pdo, $r);
            if ($r['huerfanos'] > 0) {
                $r['muestra'] = $pdo->query(
                    cat_sql_muestra_huerfanos($r['hijo'], $r['col_hijo'], $r['padre'], $r['col_padre'], 5)
                )->fetchAll(PDO::FETCH_COLUMN);
                $totalHuerfanos += $r['huerfanos'];
            }
        } catch (PDOException $ex) {
            $r['huerfanos'] = null;   // se muestra como «No disponible»
        }
        $relaciones[] = $r;
    }

    return [
        'tablas'          => $tablas,
        'relaciones'      => $relaciones,
        'descartadas'     => $det['descartadas'],
        'total_huerfanos' => $totalHuerfanos,
    ];
}

/** Recorta un nombre largo para que quepa en la caja (el nombre completo va en <title>). */
function carto_corto(string $s, int $max = 18): string
{
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
}

/**
 * Elige hasta $max tablas para dibujar y las ordena para que las relacionadas
 * queden cerca (recorrido por vecindad): primero las que participan en alguna
 * relación; las sueltas van al final.
 *
 * @return array{0:array,1:int} [tablas elegidas, cuántas quedaron fuera]
 */
function carto_ordenar_tablas(array $tablas, array $relaciones, int $max): array
{
    $vecinos = array_fill_keys($tablas, []);
    foreach ($relaciones as $r) {
        if (isset($vecinos[$r['hijo']], $vecinos[$r['padre']]) && $r['hijo'] !== $r['padre']) {
            $vecinos[$r['hijo']][$r['padre']]  = true;
            $vecinos[$r['padre']][$r['hijo']] = true;
        }
    }
    $conRel = array_filter($tablas, fn($t) => $vecinos[$t] !== []);
    usort($conRel, fn($a, $b) => count($vecinos[$b]) <=> count($vecinos[$a]) ?: strcmp($a, $b));
    $sueltas = array_values(array_diff($tablas, $conRel));
    sort($sueltas);

    $orden = [];
    $visto = [];
    foreach ($conRel as $inicio) {
        if (isset($visto[$inicio])) {
            continue;
        }
        $cola = [$inicio];
        $visto[$inicio] = true;
        while ($cola) {
            $t = array_shift($cola);
            $orden[] = $t;
            $v = array_keys($vecinos[$t]);
            sort($v);
            foreach ($v as $n) {
                if (!isset($visto[$n])) {
                    $visto[$n] = true;
                    $cola[] = $n;
                }
            }
        }
    }
    $orden = array_merge($orden, $sueltas);
    return [array_slice($orden, 0, $max), max(0, count($orden) - $max)];
}

/** Punto donde la recta centro→destino sale del rectángulo de la caja. */
function carto_borde(array $caja, float $tx, float $ty): array
{
    $dx = $tx - $caja['x'];
    $dy = $ty - $caja['y'];
    if ($dx == 0.0 && $dy == 0.0) {
        return [$caja['x'], $caja['y']];
    }
    $k = min(
        $dx != 0.0 ? ($caja['w'] / 2) / abs($dx) : INF,
        $dy != 0.0 ? ($caja['h'] / 2) / abs($dy) : INF
    );
    return [$caja['x'] + $dx * $k, $caja['y'] + $dy * $k];
}

/**
 * Dibuja el mapa como SVG (generado en el servidor, sin JavaScript).
 * Cada caja es una tabla; cada flecha va del hijo (tiene la llave foránea) al padre.
 * Trazo continuo = declarada, discontinuo = inferida, rojo = tiene huérfanos.
 * Todo texto proveniente de la base pasa por e().
 *
 * @return array{0:string,1:int} [código SVG, tablas que no cupieron]
 */
function carto_svg(array $tablas, array $relaciones, ?string $foco = null, int $max = CARTO_MAX_TABLAS): array
{
    [$dibujar, $fuera] = carto_ordenar_tablas($tablas, $relaciones, $max);
    $n = count($dibujar);
    $W = 960;
    $H = $n > 10 ? 640 : 520;
    $cajaH = 36;

    // Posición: una elipse (hasta 10 tablas) o dos elipses concéntricas (hasta 20).
    $anillos = $n > 10
        ? [[array_slice($dibujar, 0, (int) ceil($n * 0.65)), 1.0], [array_slice($dibujar, (int) ceil($n * 0.65)), 0.5]]
        : [[$dibujar, 1.0]];
    $cajas = [];
    foreach ($anillos as [$lista, $escala]) {
        $m = count($lista);
        foreach ($lista as $i => $t) {
            $ancho = max(96, min(190, 14 + 7.4 * mb_strlen(carto_corto($t))));
            if ($n === 1) {
                [$x, $y] = [$W / 2, $H / 2];
            } else {
                $ang = -M_PI / 2 + 2 * M_PI * $i / max(1, $m);
                $x = $W / 2 + ($W / 2 - 120) * $escala * cos($ang);
                $y = $H / 2 + ($H / 2 - 50) * $escala * sin($ang);
            }
            $cajas[$t] = ['x' => $x, 'y' => $y, 'w' => $ancho, 'h' => $cajaH];
        }
    }

    // Relaciones entre tablas dibujadas; las que comparten el mismo par se curvan distinto.
    $pares = [];
    foreach ($relaciones as $r) {
        if (isset($cajas[$r['hijo']], $cajas[$r['padre']])) {
            $pares[min($r['hijo'], $r['padre']) . "\0" . max($r['hijo'], $r['padre'])][] = $r;
        }
    }

    $svg  = '<svg class="mapa-svg" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-labelledby="mapa-t mapa-d">';
    $svg .= '<title id="mapa-t">Mapa de relaciones entre tablas</title>';
    $svg .= '<desc id="mapa-d">Cada caja es una tabla; cada flecha va de la tabla hija a la tabla padre.</desc>';
    $svg .= '<defs>'
          . '<marker id="pta" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z" class="mr-punta"/></marker>'
          . '<marker id="pta-h" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z" class="mr-punta-h"/></marker>'
          . '</defs>';

    foreach ($pares as $grupo) {
        $cnt = count($grupo);
        foreach ($grupo as $k => $r) {
            $a = $cajas[$r['hijo']];
            $b = $cajas[$r['padre']];
            $clase = ($r['huerfanos'] ?? 0) > 0 ? 'mr-lin mr-huer' : ($r['origen'] === 'inferida' ? 'mr-lin mr-inf' : 'mr-lin mr-decl');
            $marca = ($r['huerfanos'] ?? 0) > 0 ? 'pta-h' : 'pta';
            $tit   = $r['hijo'] . '.' . $r['col_hijo'] . ' → ' . $r['padre'] . '.' . $r['col_padre']
                   . ' (' . $r['origen'] . (($r['huerfanos'] ?? 0) > 0 ? ', ' . $r['huerfanos'] . ' huérfanos' : '') . ')';

            if ($r['hijo'] === $r['padre']) {   // relación de una tabla consigo misma: lazo sobre la caja
                $x = $a['x'];
                $y = $a['y'] - $a['h'] / 2;
                $d = sprintf('M%.1f %.1f C%.1f %.1f %.1f %.1f %.1f %.1f', $x - 14, $y, $x - 30, $y - 40, $x + 30, $y - 40, $x + 14, $y);
            } else {
                $vx = $b['x'] - $a['x'];
                $vy = $b['y'] - $a['y'];
                $len = max(1.0, sqrt($vx * $vx + $vy * $vy));
                $desv = ($k - ($cnt - 1) / 2) * 34;     // curvatura distinta por relación del mismo par
                $cx = ($a['x'] + $b['x']) / 2 - $vy / $len * $desv;
                $cy = ($a['y'] + $b['y']) / 2 + $vx / $len * $desv;
                [$x1, $y1] = carto_borde($a, $cx, $cy);
                [$x2, $y2] = carto_borde($b, $cx, $cy);
                $d = sprintf('M%.1f %.1f Q%.1f %.1f %.1f %.1f', $x1, $y1, $cx, $cy, $x2, $y2);
            }
            $svg .= '<path class="' . $clase . '" d="' . $d . '" marker-end="url(#' . $marca . ')"><title>' . e($tit) . '</title></path>';
        }
    }

    foreach ($cajas as $t => $c) {
        $resalta = ($foco !== null && $t === $foco) ? ' mr-foco' : '';
        $svg .= '<g class="mr-tabla' . $resalta . '"><title>' . e($t) . '</title>'
              . sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%d" rx="7"/>', $c['x'] - $c['w'] / 2, $c['y'] - $c['h'] / 2, $c['w'], $c['h'])
              . sprintf('<text x="%.1f" y="%.1f" text-anchor="middle" dominant-baseline="central">%s</text>', $c['x'], $c['y'], e(carto_corto($t)))
              . '</g>';
    }
    return [$svg . '</svg>', $fuera];
}
