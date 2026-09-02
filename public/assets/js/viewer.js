/* Interface paroissien : thème clair/sombre et taille du texte (stockés en cookies). */
(function () {
    'use strict';

    var MIN = 0.9, MAX = 1.8, STEP = 0.1;
    var root = document.documentElement;

    function setCookie(name, value) {
        var d = new Date();
        d.setFullYear(d.getFullYear() + 1);
        document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + d.toUTCString() + ';path=/;samesite=lax';
    }

    function currentScale() {
        var v = parseFloat(getComputedStyle(root).getPropertyValue('--chant-scale')) || 1;
        return Math.min(MAX, Math.max(MIN, v));
    }

    function applyScale(scale) {
        scale = Math.round(Math.min(MAX, Math.max(MIN, scale)) * 100) / 100;
        root.style.setProperty('--chant-scale', scale);
        setCookie('text_scale', scale);
    }

    document.querySelectorAll('[data-font]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var dir = btn.getAttribute('data-font') === '+' ? STEP : -STEP;
            applyScale(currentScale() + dir);
        });
    });

    function effectiveTheme() {
        var explicit = root.getAttribute('data-bs-theme');
        if (explicit) return explicit;
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    var toggle = document.querySelector('[data-theme-toggle]');
    if (toggle) {
        toggle.addEventListener('click', function () {
            var next = effectiveTheme() === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-bs-theme', next);
            root.setAttribute('data-theme-pref', next === 'dark' ? 'sombre' : 'clair');
            setCookie('theme', next === 'dark' ? 'sombre' : 'clair');
        });
    }
})();
