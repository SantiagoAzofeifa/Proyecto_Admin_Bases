// Tablero interactivo del Módulo 05 (Cartógrafo de relaciones).
// Lee los datos que cartografo.php deja en <script id="carto-datos"> y dibuja un
// diagrama entidad-relación: cada tabla con sus llaves y las columnas que la
// relacionan, y cada relación unida columna a columna.
// Todo el texto que viene de la base se inserta con textContent; nunca con innerHTML.
// Solo lee: no envía nada al servidor. La disposición que arme el usuario se
// recuerda en este navegador (localStorage) para la base actual.
(function () {
    'use strict';

    var nodoDatos = document.getElementById('carto-datos');
    var lienzo = document.getElementById('tablero-lienzo');
    var panel = document.getElementById('tablero-panel');
    var tablero = document.getElementById('tablero');
    if (!nodoDatos || !lienzo || !panel || !tablero) return;

    var D;
    try { D = JSON.parse(nodoDatos.textContent); } catch (e) { return; }
    if (!D || !D.tablas || !D.tablas.length) return;

    var NS = 'http://www.w3.org/2000/svg';
    var poco = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var CAB = 42, FILA = 23, ANCHO = 236, PIE = 22, PAD = 9, SEP_X = 84, SEP_Y = 34;
    var CLAVE_POS = 'carto-pos:' + D.base;

    // ------------------------------------------------------------------
    // Modelo
    // ------------------------------------------------------------------
    var tablas = {};
    var orden = [];
    D.tablas.forEach(function (t) {
        tablas[t.nombre] = {
            nombre: t.nombre, filas: t.filas, columnas: t.columnas || [],
            pk: {}, fk: {}, rels: [], filaY: {}, x: 0, y: 0, w: ANCHO, h: CAB, capa: 0
        };
        (t.columnas || []).forEach(function (c) { if (c.k === 'PRI') tablas[t.nombre].pk[c.n] = true; });
        orden.push(t.nombre);
    });
    var rels = (D.relaciones || []).filter(function (r) { return tablas[r.hijo] && tablas[r.padre]; });
    var relPorId = {};
    rels.forEach(function (r) {
        relPorId[r.id] = r;
        tablas[r.hijo].rels.push(r);
        if (r.padre !== r.hijo) tablas[r.padre].rels.push(r);
        tablas[r.hijo].fk[r.colHijo] = r.origen;
    });

    // Columnas que se dibujan: llave primaria y las que participan en alguna relación.
    orden.forEach(function (n) {
        var t = tablas[n];
        var importa = {};
        Object.keys(t.pk).forEach(function (c) { importa[c] = true; });
        t.rels.forEach(function (r) {
            if (r.hijo === n) importa[r.colHijo] = true;
            if (r.padre === n) importa[r.colPadre] = true;
        });
        t.vis = t.columnas.filter(function (c) { return importa[c.n]; });
        if (!t.vis.length) t.vis = t.columnas.slice(0, 2);
        t.ocultas = t.columnas.length - t.vis.length;
        t.h = CAB + t.vis.length * FILA + (t.ocultas > 0 ? PIE : 0) + PAD;
        t.vis.forEach(function (c, i) { t.filaY[c.n] = CAB + 4 + i * FILA + FILA / 2; });
    });

    // ------------------------------------------------------------------
    // Disposición automática: las tablas padre a la izquierda, sus hijas a la derecha.
    // ------------------------------------------------------------------
    function disponer() {
        var prof = {};
        function profundidad(n, visitando) {
            if (prof[n] !== undefined) return prof[n];
            if (visitando[n]) return 0;                // ciclo: se corta
            visitando[n] = true;
            var m = 0;
            tablas[n].rels.forEach(function (r) {
                if (r.hijo === n && r.padre !== n) m = Math.max(m, profundidad(r.padre, visitando) + 1);
            });
            delete visitando[n];
            prof[n] = m;
            return m;
        }
        var conRel = orden.filter(function (n) { return tablas[n].rels.length > 0; });
        var sueltas = orden.filter(function (n) { return tablas[n].rels.length === 0; });
        conRel.forEach(function (n) { tablas[n].capa = profundidad(n, {}); });
        var capas = [];
        conRel.forEach(function (n) { (capas[tablas[n].capa] = capas[tablas[n].capa] || []).push(n); });
        // Las tablas sin relaciones van a la columna con menos altura, no a una columna propia.
        sueltas.forEach(function (n) {
            if (!capas.length) { capas[0] = []; }
            var mejor = 0, menor = Infinity;
            capas.forEach(function (lista, i) {
                var alto = (lista || []).reduce(function (sum, x) { return sum + tablas[x].h; }, 0);
                if (alto < menor) { menor = alto; mejor = i; }
            });
            tablas[n].capa = mejor;
            (capas[mejor] = capas[mejor] || []).push(n);
        });
        var posicion = {};
        capas.forEach(function (lista, i) {
            if (!lista) return;
            if (i === 0) {
                lista.sort(function (a, b) { return (tablas[b].rels.length > 0) - (tablas[a].rels.length > 0) || tablas[b].rels.length - tablas[a].rels.length || a.localeCompare(b); });
            } else {
                // Baricentro: cada tabla cerca de sus padres, para cruzar menos líneas.
                var bar = {};
                lista.forEach(function (n) {
                    var ys = tablas[n].rels.filter(function (r) { return r.hijo === n && posicion[r.padre] !== undefined; })
                        .map(function (r) { return posicion[r.padre]; });
                    bar[n] = ys.length ? ys.reduce(function (a, b) { return a + b; }, 0) / ys.length : 999;
                });
                lista.sort(function (a, b) { return bar[a] - bar[b] || a.localeCompare(b); });
            }
            lista.forEach(function (n, k) { posicion[n] = k; });
        });
        var alturas = capas.map(function (lista) {
            return (lista || []).reduce(function (s, n) { return s + tablas[n].h + SEP_Y; }, -SEP_Y);
        });
        var maxAlto = Math.max.apply(null, alturas.concat([0]));
        capas.forEach(function (lista, i) {
            if (!lista) return;
            var y = (maxAlto - alturas[i]) / 2;
            lista.forEach(function (n) {
                tablas[n].x = i * (ANCHO + SEP_X);
                tablas[n].y = y;
                y += tablas[n].h + SEP_Y;
            });
        });
    }

    function leerPosiciones() {
        try {
            var p = JSON.parse(localStorage.getItem(CLAVE_POS) || 'null');
            if (!p) return false;
            var alguna = false;
            Object.keys(p).forEach(function (n) {
                if (tablas[n] && Array.isArray(p[n])) { tablas[n].x = +p[n][0] || 0; tablas[n].y = +p[n][1] || 0; alguna = true; }
            });
            return alguna;
        } catch (e) { return false; }
    }
    function guardarPosiciones() {
        var p = {};
        orden.forEach(function (n) { p[n] = [Math.round(tablas[n].x), Math.round(tablas[n].y)]; });
        try { localStorage.setItem(CLAVE_POS, JSON.stringify(p)); } catch (e) {}
    }

    // ------------------------------------------------------------------
    // Utilidades SVG y DOM
    // ------------------------------------------------------------------
    function s(tag, attrs, padre) {
        var el = document.createElementNS(NS, tag);
        if (attrs) Object.keys(attrs).forEach(function (k) { el.setAttribute(k, attrs[k]); });
        if (padre) padre.appendChild(el);
        return el;
    }
    function texto(padre, x, y, contenido, clase, extra) {
        var t = s('text', Object.assign({ x: x, y: y, 'class': clase }, extra || {}), padre);
        t.textContent = contenido;
        return t;
    }
    function h(tag, clase, contenido, padre) {
        var el = document.createElement(tag);
        if (clase) el.className = clase;
        if (contenido !== undefined && contenido !== null) el.textContent = contenido;
        if (padre) padre.appendChild(el);
        return el;
    }
    function corto(cadena, max) { return cadena.length > max ? cadena.slice(0, max - 1) + '…' : cadena; }
    function num(n) { return Number(n).toLocaleString('es-CR'); }
    function tipoCorto(t) { return t.replace(/\(\d+\)/, '').replace('unsigned', 'unsig.'); }
    function tipoDe(tabla, col) {
        var c = tablas[tabla] && tablas[tabla].columnas.filter(function (x) { return x.n === col; })[0];
        return c ? c.t : '';
    }
    var ESTRELLA = 'M0 -7 L1.9 -1.9 L7 0 L1.9 1.9 L0 7 L-1.9 1.9 L-7 0 L-1.9 -1.9 Z';

    // ------------------------------------------------------------------
    // Dibujo
    // ------------------------------------------------------------------
    var svg = s('svg', { 'class': 'er', role: 'group', 'aria-label': 'Diagrama de relaciones entre las tablas de ' + D.base });
    var defs = s('defs', null, svg);
    var patron = s('pattern', { id: 'er-cuadricula', width: 24, height: 24, patternUnits: 'userSpaceOnUse' }, defs);
    s('circle', { cx: 1, cy: 1, r: 1, 'class': 'er-punto' }, patron);
    [['er-flecha', 'er-flecha-decl'], ['er-flecha-inf', 'er-flecha-inf'], ['er-flecha-huer', 'er-flecha-huer']].forEach(function (m) {
        var mk = s('marker', { id: m[0], viewBox: '0 0 10 10', refX: 9, refY: 5, markerWidth: 7, markerHeight: 7, orient: 'auto-start-reverse' }, defs);
        s('path', { d: 'M0 0 L10 5 L0 10 z', 'class': m[1] }, mk);
    });
    var brillo = s('filter', { id: 'er-brillo', x: '-50%', y: '-50%', width: '200%', height: '200%' }, defs);
    s('feGaussianBlur', { stdDeviation: 3, result: 'b' }, brillo);
    var mezcla = s('feMerge', null, brillo);
    s('feMergeNode', { 'in': 'b' }, mezcla);
    s('feMergeNode', { 'in': 'SourceGraphic' }, mezcla);
    var fondo = s('rect', { x: -20000, y: -20000, width: 40000, height: 40000, fill: 'url(#er-cuadricula)', 'class': 'er-fondo' }, svg);
    var capaRel = s('g', { 'class': 'er-relaciones' }, svg);
    var capaTab = s('g', { 'class': 'er-tablas' }, svg);

    var nodos = {};
    var lineas = {};

    function dibujarTabla(t) {
        var g = s('g', { 'class': 'er-tabla', tabindex: 0, role: 'button' }, capaTab);
        g.dataset.tabla = t.nombre;
        var entrantes = t.rels.filter(function (r) { return r.padre === t.nombre; }).length;
        var salientes = t.rels.filter(function (r) { return r.hijo === t.nombre; }).length;
        g.setAttribute('aria-label', 'Tabla ' + t.nombre + ', ' + (t.filas === null ? 'filas no disponibles' : 'unas ' + num(t.filas) + ' filas')
            + ', apunta a ' + salientes + ' y recibe ' + entrantes + ' relaciones');
        var dentro = s('g', { 'class': 'er-tabla-in' }, g);
        dentro.style.setProperty('--d', (t.capa * 90) + 'ms');
        s('title', null, dentro).textContent = t.nombre;
        s('rect', { 'class': 'er-caja', width: t.w, height: t.h, rx: 9 }, dentro);
        s('path', { 'class': 'er-cab', d: 'M0 9 A9 9 0 0 1 9 0 H' + (t.w - 9) + ' A9 9 0 0 1 ' + t.w + ' 9 V' + CAB + ' H0 Z' }, dentro);
        texto(dentro, 12, 19, corto(t.nombre, 26), 'er-nombre');
        texto(dentro, 12, 34, t.filas === null ? 'filas no disponibles' : '≈ ' + num(t.filas) + ' filas (estimado)', 'er-filas');
        t.vis.forEach(function (c, i) {
            var y = CAB + 4 + i * FILA;
            var fila = s('g', { 'class': 'er-fila' }, dentro);
            fila.dataset.col = c.n;
            if (t.pk[c.n]) {
                s('rect', { 'class': 'er-llave er-pk', x: 10, y: y + 5, width: 24, height: 14, rx: 3 }, fila);
                texto(fila, 22, y + 15.5, 'PK', 'er-llave-txt er-pk-txt', { 'text-anchor': 'middle' });
            } else if (t.fk[c.n]) {
                var inf = t.fk[c.n] === 'inferida';
                s('rect', { 'class': 'er-llave er-fk' + (inf ? ' er-fk-inf' : ''), x: 10, y: y + 5, width: 24, height: 14, rx: 3 }, fila);
                texto(fila, 22, y + 15.5, 'FK', 'er-llave-txt er-fk-txt', { 'text-anchor': 'middle' });
            }
            texto(fila, 42, y + 16, corto(c.n, 17), 'er-col');
            texto(fila, t.w - 10, y + 16, corto(tipoCorto(c.t), 14), 'er-tipo', { 'text-anchor': 'end' });
        });
        if (t.ocultas > 0) {
            texto(dentro, 12, CAB + 4 + t.vis.length * FILA + 15, '+ ' + t.ocultas + (t.ocultas === 1 ? ' columna más' : ' columnas más'), 'er-mas');
        }
        nodos[t.nombre] = g;
        moverNodo(t);
    }

    function moverNodo(t) { nodos[t.nombre].setAttribute('transform', 'translate(' + t.x + ' ' + t.y + ')'); }

    function trazo(r) {
        var A = tablas[r.hijo], B = tablas[r.padre];
        var ya = A.y + (A.filaY[r.colHijo] || CAB / 2);
        var yb = B.y + (B.filaY[r.colPadre] || CAB / 2);
        var sx, ex, ca, cb;
        if (A === B) {                                   // relación de una tabla consigo misma
            sx = A.x + A.w; ex = A.x + A.w;
            return { d: 'M' + sx + ' ' + ya + ' C' + (sx + 70) + ' ' + ya + ' ' + (ex + 70) + ' ' + yb + ' ' + ex + ' ' + yb, mx: sx + 52, my: (ya + yb) / 2 };
        }
        var misma = Math.abs(A.x - B.x) < A.w * 0.8;     // una encima de la otra
        if (misma) {
            sx = A.x + A.w; ex = B.x + B.w; ca = sx + 70; cb = ex + 70;
        } else if (A.x + A.w / 2 >= B.x + B.w / 2) {
            sx = A.x; ex = B.x + B.w;
            var k = Math.max(50, (sx - ex) / 2); ca = sx - k; cb = ex + k;
        } else {
            sx = A.x + A.w; ex = B.x;
            var k2 = Math.max(50, (ex - sx) / 2); ca = sx + k2; cb = ex - k2;
        }
        return {
            d: 'M' + sx + ' ' + ya + ' C' + ca + ' ' + ya + ' ' + cb + ' ' + yb + ' ' + ex + ' ' + yb,
            mx: (sx + 3 * ca + 3 * cb + ex) / 8, my: (ya + 3 * ya + 3 * yb + yb) / 8
        };
    }

    function dibujarRelacion(r, retraso) {
        var huer = r.huerfanos !== null && r.huerfanos > 0;
        var tipo = r.origen === 'inferida' ? 'inf' : 'decl';
        var g = s('g', { 'class': 'er-rel er-' + tipo + (huer ? ' er-huer' : '') + (r.huerfanos === null ? ' er-nd' : '') }, capaRel);
        g.dataset.rel = r.id;
        s('title', null, g).textContent = r.hijo + '.' + r.colHijo + ' → ' + r.padre + '.' + r.colPadre
            + ' (' + r.origen + (huer ? ', ' + r.huerfanos + ' huérfanos' : '') + ')';
        var linea = s('path', {
            'class': 'er-linea',
            'marker-end': 'url(#' + (huer ? 'er-flecha-huer' : (tipo === 'inf' ? 'er-flecha-inf' : 'er-flecha')) + ')'
        }, g);
        // pathLength=1 permite "dibujar" la línea al entrar; las inferidas no lo llevan
        // porque su trazo discontinuo se mide en unidades reales.
        if (tipo !== 'inf' || huer) linea.setAttribute('pathLength', 1);
        linea.style.setProperty('--d', retraso + 'ms');
        var golpe = s('path', { 'class': 'er-golpe' }, g);
        var marca = null, insignia = null;
        if (tipo === 'inf') {
            marca = s('path', { 'class': 'er-estrella', d: ESTRELLA }, g);
            marca.style.setProperty('--d', (retraso + 200) + 'ms');
        }
        if (huer) {
            insignia = s('g', { 'class': 'er-insignia' }, g);
            s('circle', { r: 11 }, insignia);
            texto(insignia, 0, 4, r.huerfanos > 99 ? '99+' : String(r.huerfanos), 'er-insignia-txt', { 'text-anchor': 'middle' });
        }
        lineas[r.id] = { g: g, linea: linea, golpe: golpe, marca: marca, insignia: insignia };
        trazarRelacion(r);
    }

    function trazarRelacion(r) {
        var p = trazo(r), l = lineas[r.id];
        l.linea.setAttribute('d', p.d);
        l.golpe.setAttribute('d', p.d);
        var mx = p.mx, my = p.my;
        if (l.marca) l.marca.setAttribute('transform', 'translate(' + mx + ' ' + (l.insignia ? my - 16 : my) + ')');
        if (l.insignia) l.insignia.setAttribute('transform', 'translate(' + mx + ' ' + (l.marca ? my + 4 : my) + ')');
    }

    function dibujarTodo() {
        while (capaRel.firstChild) capaRel.removeChild(capaRel.firstChild);
        while (capaTab.firstChild) capaTab.removeChild(capaTab.firstChild);
        nodos = {}; lineas = {};
        var maxCapa = orden.reduce(function (m, n) { return Math.max(m, tablas[n].capa); }, 0);
        orden.forEach(function (n) { dibujarTabla(tablas[n]); });
        rels.forEach(function (r, i) { dibujarRelacion(r, maxCapa * 90 + 260 + i * 60); });
    }

    // ------------------------------------------------------------------
    // Vista: mover y acercar (viewBox)
    // ------------------------------------------------------------------
    var vista = { x: 0, y: 0, w: 1000, h: 560 };
    function aplicarVista() { svg.setAttribute('viewBox', vista.x + ' ' + vista.y + ' ' + vista.w + ' ' + vista.h); }
    function proporcion() { var r = svg.getBoundingClientRect(); return r.width > 0 ? r.height / r.width : 0.56; }
    function escala() { var r = svg.getBoundingClientRect(); return r.width > 0 ? vista.w / r.width : 1; }

    function encuadrar() {
        var x1 = Infinity, y1 = Infinity, x2 = -Infinity, y2 = -Infinity;
        orden.forEach(function (n) {
            var t = tablas[n];
            x1 = Math.min(x1, t.x); y1 = Math.min(y1, t.y);
            x2 = Math.max(x2, t.x + t.w + 60); y2 = Math.max(y2, t.y + t.h);
        });
        var m = 44, w = (x2 - x1) + 2 * m, hgt = (y2 - y1) + 2 * m, k = proporcion();
        if (hgt / w > k) { var nw = hgt / k; x1 -= (nw - w) / 2; w = nw; } else { var nh = w * k; y1 -= (nh - hgt) / 2; hgt = nh; }
        vista = { x: x1 - m, y: y1 - m, w: w, h: hgt };
        aplicarVista();
    }

    function acercar(f, cx, cy) {
        var nw = Math.min(8000, Math.max(260, vista.w * f));
        var f2 = nw / vista.w;
        if (cx === undefined) { cx = vista.x + vista.w / 2; cy = vista.y + vista.h / 2; }
        vista = { x: cx - (cx - vista.x) * f2, y: cy - (cy - vista.y) * f2, w: nw, h: vista.h * f2 };
        aplicarVista();
    }

    function centrarEn(n) {
        var t = tablas[n];
        vista.x = t.x + t.w / 2 - vista.w / 2;
        vista.y = t.y + t.h / 2 - vista.h / 2;
        aplicarVista();
    }

    function puntoSvg(ev) {
        var r = svg.getBoundingClientRect();
        return { x: vista.x + (ev.clientX - r.left) * vista.w / r.width, y: vista.y + (ev.clientY - r.top) * vista.h / r.height };
    }

    // ------------------------------------------------------------------
    // Resaltado y selección
    // ------------------------------------------------------------------
    var seleccion = null;     // { tipo: 'tabla' | 'rel', id }

    function marcar(tablasActivas, relsActivas) {
        svg.classList.add('er-foco');
        orden.forEach(function (n) { nodos[n].classList.toggle('activa', !!tablasActivas[n]); });
        rels.forEach(function (r) { lineas[r.id].g.classList.toggle('activa', !!relsActivas[r.id]); });
    }
    function desmarcar() {
        svg.classList.remove('er-foco');
        orden.forEach(function (n) { nodos[n].classList.remove('activa', 'elegida'); });
        rels.forEach(function (r) { lineas[r.id].g.classList.remove('activa', 'elegida'); });
    }
    function vecindadTabla(n) {
        var ts = {}, rs = {};
        ts[n] = true;
        tablas[n].rels.forEach(function (r) { rs[r.id] = true; ts[r.hijo] = true; ts[r.padre] = true; });
        return [ts, rs];
    }
    function vecindadRel(id) {
        var r = relPorId[id], ts = {}, rs = {};
        ts[r.hijo] = true; ts[r.padre] = true; rs[id] = true;
        return [ts, rs];
    }
    function mostrarSeleccion() {
        desmarcar();
        if (!seleccion) return;
        var v = seleccion.tipo === 'tabla' ? vecindadTabla(seleccion.id) : vecindadRel(seleccion.id);
        marcar(v[0], v[1]);
        if (seleccion.tipo === 'tabla') nodos[seleccion.id].classList.add('elegida');
        else lineas[seleccion.id].g.classList.add('elegida');
    }
    function previsualizar(v) { if (v) marcar(v[0], v[1]); else mostrarSeleccion(); }

    function abrirPanel() { tablero.classList.add('con-panel'); }
    function elegirTabla(n, centrar) {
        seleccion = { tipo: 'tabla', id: n };
        abrirPanel();
        mostrarSeleccion();
        panelTabla(tablas[n]);
        if (centrar) centrarEn(n);
    }
    function elegirRel(id, centrar) {
        seleccion = { tipo: 'rel', id: id };
        abrirPanel();
        mostrarSeleccion();
        panelRel(relPorId[id]);
        if (centrar) {
            var r = relPorId[id], a = tablas[r.hijo], b = tablas[r.padre];
            vista.x = (a.x + b.x + (a.w + b.w) / 2) / 2 - vista.w / 2;
            vista.y = (a.y + b.y + (a.h + b.h) / 2) / 2 - vista.h / 2;
            aplicarVista();
        }
    }
    function soltar() { seleccion = null; mostrarSeleccion(); tablero.classList.remove('con-panel'); panelInicio(); }

    // ------------------------------------------------------------------
    // Panel de detalle
    // ------------------------------------------------------------------
    var contenidoInicial = panel.cloneNode(true);
    function vaciarPanel() {
        while (panel.firstChild) panel.removeChild(panel.firstChild);
        var cerrar = h('button', 'panel-cerrar', null, panel);
        cerrar.type = 'button';
        cerrar.setAttribute('aria-label', 'Cerrar el detalle');
        cerrar.title = 'Cerrar el detalle (Esc)';
        cerrar.addEventListener('click', soltar);
    }
    function panelInicio() {
        vaciarPanel();
        Array.prototype.forEach.call(contenidoInicial.childNodes, function (n) { panel.appendChild(n.cloneNode(true)); });
        var conHuer = rels.filter(function (r) { return r.huerfanos > 0; }).length;
        var p = h('p', 'panel-resumen', null, panel);
        p.textContent = orden.length + ' tablas y ' + rels.length + ' relaciones. '
            + (conHuer ? conHuer + (conHuer === 1 ? ' relación tiene' : ' relaciones tienen') + ' filas huérfanas.' : 'Ninguna relación tiene filas huérfanas.');
    }
    function estadoInsignia(estado, padre) {
        var clase = estado === 'OK' ? 'ok' : (estado === 'ADVERTENCIA' ? 'huer' : 'nd');
        return h('span', 'panel-estado panel-estado-' + clase, estado, padre);
    }
    function fila(dl, clave, valor, ident) {
        var div = h('div', null, null, dl);
        h('dt', null, clave, div);
        h('dd', ident ? 'ident' : null, valor, div);
    }

    function panelTabla(t) {
        vaciarPanel();
        h('p', 'panel-tipo', 'Tabla', panel);
        h('h3', 'ident', t.nombre, panel);
        h('p', 'panel-filas', t.filas === null ? 'Filas no disponibles' : '≈ ' + num(t.filas) + ' filas, estimado del motor (TABLE_ROWS)', panel);

        h('h4', null, 'Columnas', panel);
        var ul = h('ul', 'panel-columnas', null, panel);
        t.columnas.forEach(function (c) {
            var li = h('li', null, null, ul);
            var insignia = t.pk[c.n] ? 'PK' : (t.fk[c.n] ? 'FK' : '');
            h('span', 'llave' + (insignia ? ' llave-' + insignia.toLowerCase() : ' llave-vacia'), insignia, li);
            h('span', 'ident panel-col', c.n, li);
            h('span', 'ident panel-tipo-col', c.t, li);
        });

        h('h4', null, 'Relaciones', panel);
        if (!t.rels.length) {
            h('p', 'panel-nota', 'Esta tabla no se relaciona con ninguna otra: no tiene llaves foráneas ni columnas que coincidan con la llave de otra tabla.', panel);
        } else {
            var lista = h('ul', 'panel-rels', null, panel);
            t.rels.forEach(function (r) {
                var li = h('li', null, null, lista);
                var b = h('button', 'panel-rel', null, li);
                b.type = 'button';
                var sale = r.hijo === t.nombre;
                h('span', 'panel-rel-dir', sale ? 'Apunta a' : 'Recibe de', b);
                h('span', 'ident', sale ? r.padre + '.' + r.colPadre : r.hijo + '.' + r.colHijo, b);
                h('span', 'panel-rel-origen', r.origen, b);
                estadoInsignia(r.estado, b);
                b.addEventListener('click', function () { elegirRel(r.id, true); });
            });
        }
        var acciones = h('p', 'panel-acciones', null, panel);
        var a = h('a', 'btn-secundario', 'Analizar solo esta tabla', acciones);
        a.href = 'cartografo.php?tabla=' + encodeURIComponent(t.nombre);
    }

    function panelRel(r) {
        vaciarPanel();
        h('p', 'panel-tipo', r.origen === 'inferida' ? 'Relación inferida' : 'Relación declarada', panel);
        var tit = h('h3', 'panel-rel-titulo', null, panel);
        h('span', 'ident', r.hijo + '.' + r.colHijo, tit);
        h('span', 'panel-flecha', 'apunta a', tit);
        h('span', 'ident', r.padre + '.' + r.colPadre, tit);
        estadoInsignia(r.estado, panel);

        var dl = h('dl', 'panel-datos', null, panel);
        fila(dl, 'Origen', r.origen === 'inferida' ? 'Deducida por nombre y tipo' : 'FOREIGN KEY declarada');
        if (r.origen === 'inferida') {
            fila(dl, 'Regla', r.regla === 'patron' ? 'Patrón de nombre' : 'Mismo nombre que la llave');
            fila(dl, 'Confianza', r.confianza || '—');
        }
        fila(dl, 'Tipo en la hija', tipoDe(r.hijo, r.colHijo), true);
        fila(dl, 'Tipo en la padre', tipoDe(r.padre, r.colPadre), true);
        fila(dl, 'Filas huérfanas', r.huerfanos === null ? 'No disponible' : num(r.huerfanos));
        if (r.muestra && r.muestra.length) fila(dl, 'Valores sin padre', r.muestra.join(', '), true);
        if (r.motivo) h('p', 'panel-nota', r.motivo, panel);

        h('h4', null, 'Consulta que cuenta los huérfanos', panel);
        var pre = h('pre', 'sql', r.sql, panel);
        var acciones = h('p', 'panel-acciones', null, panel);
        var copiar = h('button', 'btn-secundario', 'Copiar consulta', acciones);
        copiar.type = 'button';
        copiar.addEventListener('click', function () {
            var hecho = function () { copiar.textContent = 'Consulta copiada'; setTimeout(function () { copiar.textContent = 'Copiar consulta'; }, 1800); };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(r.sql).then(hecho, function () { seleccionarTexto(pre); });
            } else { seleccionarTexto(pre); }
        });
        [['Ver tabla hija', r.hijo], ['Ver tabla padre', r.padre]].forEach(function (par) {
            var b = h('button', 'btn-secundario', par[0], acciones);
            b.type = 'button';
            b.addEventListener('click', function () { elegirTabla(par[1], true); });
        });
    }
    function seleccionarTexto(el) {
        var rango = document.createRange();
        rango.selectNodeContents(el);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(rango);
    }

    // ------------------------------------------------------------------
    // Eventos del lienzo: arrastrar tablas, mover el fondo, acercar
    // ------------------------------------------------------------------
    var arrastre = null;

    svg.addEventListener('pointerdown', function (ev) {
        if (ev.button !== 0) return;
        var nodo = ev.target.closest('.er-tabla');
        var rel = ev.target.closest('.er-rel');
        var p0 = puntoSvg(ev);
        arrastre = { nodo: nodo, rel: rel, x0: ev.clientX, y0: ev.clientY, p0: p0, movido: false,
                     vx: vista.x, vy: vista.y, tx: nodo ? tablas[nodo.dataset.tabla].x : 0, ty: nodo ? tablas[nodo.dataset.tabla].y : 0 };
        svg.setPointerCapture(ev.pointerId);
    });
    svg.addEventListener('pointermove', function (ev) {
        if (!arrastre) return;
        var dx = ev.clientX - arrastre.x0, dy = ev.clientY - arrastre.y0;
        if (!arrastre.movido && Math.abs(dx) + Math.abs(dy) < 4) return;
        arrastre.movido = true;
        var k = escala();
        if (arrastre.nodo) {
            svg.classList.add('arrastrando');
            var t = tablas[arrastre.nodo.dataset.tabla];
            t.x = arrastre.tx + dx * k;
            t.y = arrastre.ty + dy * k;
            moverNodo(t);
            t.rels.forEach(trazarRelacion);
        } else {
            svg.classList.add('moviendo');
            vista.x = arrastre.vx - dx * k;
            vista.y = arrastre.vy - dy * k;
            aplicarVista();
        }
    });
    function terminar() {
        if (!arrastre) return;
        var a = arrastre;
        arrastre = null;
        svg.classList.remove('arrastrando', 'moviendo');
        if (a.movido) { if (a.nodo) guardarPosiciones(); return; }
        if (a.nodo) elegirTabla(a.nodo.dataset.tabla, false);
        else if (a.rel) elegirRel(+a.rel.dataset.rel, false);
        else soltar();
    }
    svg.addEventListener('pointerup', terminar);
    svg.addEventListener('pointercancel', function () { arrastre = null; svg.classList.remove('arrastrando', 'moviendo'); });

    svg.addEventListener('wheel', function (ev) {
        if (!ev.ctrlKey && !ev.metaKey && !document.fullscreenElement) {
            lienzo.classList.add('ver-ayuda');
            clearTimeout(lienzo._ayuda);
            lienzo._ayuda = setTimeout(function () { lienzo.classList.remove('ver-ayuda'); }, 1400);
            return;
        }
        ev.preventDefault();
        var p = puntoSvg(ev);
        acercar(ev.deltaY > 0 ? 1.12 : 1 / 1.12, p.x, p.y);
    }, { passive: false });

    svg.addEventListener('pointerover', function (ev) {
        if (arrastre) return;
        var nodo = ev.target.closest('.er-tabla');
        var rel = ev.target.closest('.er-rel');
        if (nodo) previsualizar(vecindadTabla(nodo.dataset.tabla));
        else if (rel) previsualizar(vecindadRel(+rel.dataset.rel));
    });
    svg.addEventListener('pointerout', function (ev) {
        if (arrastre) return;
        var hacia = ev.relatedTarget && ev.relatedTarget.closest ? ev.relatedTarget.closest('.er-tabla, .er-rel') : null;
        if (!hacia) previsualizar(null);
    });

    // Teclado: Tab recorre las tablas, Enter las elige, flechas las mueven, Esc limpia.
    svg.addEventListener('keydown', function (ev) {
        var nodo = ev.target.closest && ev.target.closest('.er-tabla');
        if (ev.key === 'Escape') { soltar(); return; }
        if (!nodo) return;
        var t = tablas[nodo.dataset.tabla];
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); elegirTabla(t.nombre, false); return; }
        var paso = ev.shiftKey ? 40 : 10, mover = { ArrowLeft: [-paso, 0], ArrowRight: [paso, 0], ArrowUp: [0, -paso], ArrowDown: [0, paso] }[ev.key];
        if (mover) {
            ev.preventDefault();
            t.x += mover[0]; t.y += mover[1];
            moverNodo(t); t.rels.forEach(trazarRelacion); guardarPosiciones();
        }
    });
    svg.addEventListener('focusin', function (ev) {
        var nodo = ev.target.closest && ev.target.closest('.er-tabla');
        if (nodo) previsualizar(vecindadTabla(nodo.dataset.tabla));
    });
    svg.addEventListener('focusout', function () { previsualizar(null); });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && tablero.classList.contains('con-panel')) soltar();
    });

    // ------------------------------------------------------------------
    // Barra de herramientas
    // ------------------------------------------------------------------
    tablero.querySelectorAll('.chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            var activo = chip.getAttribute('aria-pressed') !== 'true';
            chip.setAttribute('aria-pressed', activo ? 'true' : 'false');
            var f = chip.dataset.filtro;
            if (f === 'decl') svg.classList.toggle('ocultar-decl', !activo);
            if (f === 'inf') svg.classList.toggle('ocultar-inf', !activo);
            if (f === 'huer') {
                svg.classList.toggle('solo-huer', activo);
                orden.forEach(function (n) {
                    var conHuer = tablas[n].rels.some(function (r) { return r.huerfanos > 0; });
                    nodos[n].classList.toggle('sin-huer', !conHuer);
                });
            }
        });
    });

    var botonCompleta = tablero.querySelector('[data-accion="completa"]');
    tablero.querySelectorAll('[data-accion]').forEach(function (b) {
        b.addEventListener('click', function () {
            var a = b.dataset.accion;
            if (a === 'acercar') acercar(1 / 1.25);
            else if (a === 'alejar') acercar(1.25);
            else if (a === 'encuadrar') encuadrar();
            else if (a === 'reordenar') {
                try { localStorage.removeItem(CLAVE_POS); } catch (e) {}
                disponer();
                orden.forEach(function (n) { moverNodo(tablas[n]); });
                rels.forEach(trazarRelacion);
                encuadrar();
            } else if (a === 'completa') {
                if (document.fullscreenElement) document.exitFullscreen();
                else if (tablero.requestFullscreen) tablero.requestFullscreen();
            }
        });
    });
    if (!tablero.requestFullscreen && botonCompleta) botonCompleta.hidden = true;
    document.addEventListener('fullscreenchange', function () {
        if (botonCompleta) botonCompleta.textContent = document.fullscreenElement ? 'Salir de pantalla completa' : 'Pantalla completa';
        setTimeout(encuadrar, 60);
    });

    var buscador = document.getElementById('tablero-buscar');
    if (buscador) {
        buscador.addEventListener('input', function () {
            var q = buscador.value.trim().toLowerCase();
            svg.classList.toggle('buscando', q !== '');
            orden.forEach(function (n) { nodos[n].classList.toggle('coincide', q !== '' && n.toLowerCase().indexOf(q) !== -1); });
        });
        buscador.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Enter') return;
            ev.preventDefault();
            var q = buscador.value.trim().toLowerCase();
            var n = orden.filter(function (x) { return x.toLowerCase().indexOf(q) !== -1; })[0];
            if (n) elegirTabla(n, true);
        });
    }

    // La tabla de relaciones de abajo también maneja el mapa.
    document.querySelectorAll('.ver-rel').forEach(function (b) {
        var id = +b.dataset.rel;
        if (!relPorId[id]) return;
        b.hidden = false;
        b.addEventListener('click', function () {
            elegirRel(id, true);
            tablero.scrollIntoView({ behavior: poco ? 'auto' : 'smooth', block: 'start' });
        });
    });
    document.querySelectorAll('.tabla-rel tr[data-rel]').forEach(function (tr) {
        var id = +tr.dataset.rel;
        if (!relPorId[id]) return;
        tr.addEventListener('mouseenter', function () { previsualizar(vecindadRel(id)); });
        tr.addEventListener('mouseleave', function () { previsualizar(null); });
    });

    // ------------------------------------------------------------------
    // Arranque
    // ------------------------------------------------------------------
    disponer();
    leerPosiciones();
    var respaldo = lienzo.querySelector('.mapa-wrap');
    if (respaldo) respaldo.remove();
    lienzo.insertBefore(svg, lienzo.firstChild);
    tablero.classList.add('interactivo');
    if (!poco) svg.classList.add('er-entrada');
    dibujarTodo();
    encuadrar();
    window.addEventListener('resize', function () { aplicarVista(); });
    panelInicio();
    if (D.foco && tablas[D.foco]) elegirTabla(D.foco, false);
    setTimeout(function () { svg.classList.remove('er-entrada'); }, 3200);
})();
