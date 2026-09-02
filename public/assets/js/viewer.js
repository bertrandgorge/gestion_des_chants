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

    /* ---------- Mode présentation (projection) ---------- */
    var presBtn = document.querySelector('[data-presentation]');
    var presentation = document.getElementById('presentation');

    if (presBtn && presentation) {
        var slides = Array.prototype.slice.call(presentation.querySelectorAll('.pres-slide'));
        var progressEl = presentation.querySelector('.pres-progress');
        var index = 0;
        var black = false;
        var presScale = 1;

        function bumpScale(delta) {
            presScale = Math.min(3, Math.max(0.5, Math.round((presScale + delta) * 10) / 10));
            presentation.style.setProperty('--pres-scale', presScale);
        }

        function showSlide(i) {
            index = Math.max(0, Math.min(slides.length - 1, i));
            slides.forEach(function (s, n) { s.classList.toggle('is-current', n === index); });
            updateProgress();
        }

        function updateProgress() {
            if (!progressEl) { return; }
            var cur = slides[index];
            var n = cur && cur.getAttribute('data-couplet');
            var total = cur && cur.getAttribute('data-couplet-total');
            if (n && total) {
                progressEl.textContent = 'couplet ' + n + '/' + total;
                progressEl.hidden = false;
            } else {
                progressEl.hidden = true;
            }
        }

        function setBlack(on) {
            black = on;
            presentation.classList.toggle('is-black', black);
        }

        function enter() {
            if (!slides.length) { return; }
            showSlide(0);
            setBlack(false);
            document.body.classList.add('presentation-active');
            document.addEventListener('keydown', onKey, true);
            if (presentation.requestFullscreen) {
                presentation.requestFullscreen().catch(function () {});
            }
        }

        function leave() {
            if (!document.body.classList.contains('presentation-active')) { return; }
            document.body.classList.remove('presentation-active');
            setBlack(false);
            document.removeEventListener('keydown', onKey, true);
            if (document.fullscreenElement) {
                document.exitFullscreen().catch(function () {});
            }
        }

        function onKey(e) {
            var k = e.key;
            var isArrow = k === 'ArrowRight' || k === 'ArrowLeft' || k === 'ArrowUp' || k === 'ArrowDown';

            if (k === 'Escape') {
                e.preventDefault();
                leave();
                return;
            }
            if (k === 'b' || k === 'B') {
                e.preventDefault();
                setBlack(!black);
                return;
            }
            if (k === '+' || k === '=' || k === 'Add') {
                e.preventDefault();
                bumpScale(0.1);
                return;
            }
            if (k === '-' || k === '_' || k === 'Subtract') {
                e.preventDefault();
                bumpScale(-0.1);
                return;
            }
            if (black && (isArrow || k === 'PageUp' || k === 'PageDown' || k === ' ' || k === 'Spacebar')) {
                // Toute flèche fait sortir du noir en avançant d'un écran.
                e.preventDefault();
                setBlack(false);
                showSlide(index + 1);
                return;
            }
            if (k === 'ArrowRight' || k === 'ArrowDown' || k === 'PageDown' || k === ' ' || k === 'Spacebar') {
                e.preventDefault();
                showSlide(index + 1);
            } else if (k === 'ArrowLeft' || k === 'ArrowUp' || k === 'PageUp') {
                e.preventDefault();
                showSlide(index - 1);
            }
        }

        presBtn.addEventListener('click', enter);

        // Raccourci « p » : passe en mode présentation depuis la vue normale.
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'p' && e.key !== 'P') { return; }
            if (e.ctrlKey || e.metaKey || e.altKey) { return; }
            var t = e.target;
            if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) { return; }
            if (document.body.classList.contains('presentation-active')) { return; }
            e.preventDefault();
            enter();
        });

        presentation.addEventListener('click', function () {
            if (black) { setBlack(false); }
            showSlide(index + 1);
        });

        document.addEventListener('fullscreenchange', function () {
            if (!document.fullscreenElement) { leave(); }
        });
    }
})();
