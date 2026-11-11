// Comportamiento común de la Consola DBA: tema claro/oscuro y menú en pantallas pequeñas.
// No envía nada al servidor; el tema elegido se recuerda solo en este navegador.
(function () {
    'use strict';
    var raiz = document.documentElement;

    function temaActual() {
        if (raiz.dataset.tema) return raiz.dataset.tema;
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'oscuro' : 'claro';
    }

    function pintarBoton(btn) {
        var t = temaActual();
        btn.dataset.tema = t;
        btn.setAttribute('aria-label', t === 'oscuro' ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro');
        btn.title = btn.getAttribute('aria-label');
    }

    document.querySelectorAll('.btn-tema').forEach(function (btn) {
        pintarBoton(btn);
        btn.addEventListener('click', function () {
            var nuevo = temaActual() === 'oscuro' ? 'claro' : 'oscuro';
            raiz.dataset.tema = nuevo;
            try { localStorage.setItem('consola-tema', nuevo); } catch (e) {}
            pintarBoton(btn);
            document.dispatchEvent(new CustomEvent('consola:tema', { detail: nuevo }));
        });
    });

    var menuBtn = document.querySelector('.btn-menu');
    var lateral = document.getElementById('lateral');
    if (menuBtn && lateral) {
        menuBtn.addEventListener('click', function () {
            var abierto = lateral.classList.toggle('abierto');
            menuBtn.setAttribute('aria-expanded', abierto ? 'true' : 'false');
        });
    }
})();
