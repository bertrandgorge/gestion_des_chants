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

    /* ---------- Pincer pour ajuster la taille du texte (mobile) ---------- */
    (function () {
        var startDist = 0, startScale = 1, pinching = false;

        function distance(touches) {
            var dx = touches[0].clientX - touches[1].clientX;
            var dy = touches[0].clientY - touches[1].clientY;
            return Math.sqrt(dx * dx + dy * dy);
        }

        document.addEventListener('touchstart', function (e) {
            if (e.touches.length === 2) {
                pinching = true;
                startDist = distance(e.touches);
                startScale = currentScale();
            }
        }, { passive: true });

        document.addEventListener('touchmove', function (e) {
            if (!pinching || e.touches.length !== 2) { return; }
            e.preventDefault(); // empêche le zoom de la page
            if (startDist > 0) {
                applyScale(startScale * distance(e.touches) / startDist);
            }
        }, { passive: false });

        document.addEventListener('touchend', function (e) {
            if (e.touches.length < 2) { pinching = false; }
        });

        // iOS Safari : événements « gesture » dédiés (ignore touch-action).
        document.addEventListener('gesturestart', function (e) {
            e.preventDefault();
            pinching = true;
            startScale = currentScale();
        });
        document.addEventListener('gesturechange', function (e) {
            if (!pinching) { return; }
            e.preventDefault();
            applyScale(startScale * e.scale);
        });
        document.addEventListener('gestureend', function () { pinching = false; });
    })();

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
