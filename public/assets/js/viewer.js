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
            if (document.body.classList.contains('presentation-active')) { return; }
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
            if (document.body.classList.contains('presentation-active')) { return; }
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

    var chantLiensBtn = document.querySelector('[data-chant-liens]');
    if (chantLiensBtn) {
        chantLiensBtn.addEventListener('click', function () {
            var show = !document.body.classList.contains('show-chant-liens');
            document.body.classList.toggle('show-chant-liens', show);
            chantLiensBtn.classList.toggle('active', show);
            chantLiensBtn.setAttribute('aria-pressed', show ? 'true' : 'false');
            setCookie('chant_liens', show ? '1' : '0');
        });
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
        var autoFit = true; // tant que vrai, la taille est recalculée automatiquement
        var lastGestureEnd = 0;

        function clampScale(s) {
            return Math.min(3, Math.max(0.3, s));
        }

        // Plus grande échelle telle que la slide la plus chargée tienne dans
        // l'écran (largeur ET hauteur). Toutes les slides partagent cette
        // taille : rendu homogène, façon logiciel de projection.
        function computeAutoScale() {
            var vw = window.innerWidth, vh = window.innerHeight;
            if (!slides.length || !vw || !vh) { return 1; }

            var availW = vw * 0.84; // largeur utile : 100vw - 2 × 8vw de marge
            var availH = vh * 0.76; // hauteur utile : 100vh - 2 × 12vh de marge
            var gapV = 0.04 * vh;   // .pres-corps { gap: 4vh }
            var best = Infinity;

            presentation.classList.add('is-measuring');
            slides.forEach(function (slide) {
                var corps = slide.querySelector('.pres-corps');
                if (!corps) { return; }
                var gap = Math.max(0, corps.querySelectorAll('.pres-bloc').length - 1) * gapV;
                var rect = corps.getBoundingClientRect();
                var textH = rect.height - gap; // hauteur du texte seul, à l'échelle 1
                var lineW = rect.width;        // plus longue ligne, à l'échelle 1
                if (textH <= 0 || lineW <= 0) { return; }
                // Les marges (gap) sont en vh et ne suivent pas l'échelle : on
                // ne met à l'échelle que la part « texte » de la hauteur.
                best = Math.min(best, (availH - gap) / textH, availW / lineW);
            });
            presentation.classList.remove('is-measuring');

            if (!isFinite(best)) { return 1; }
            return clampScale(Math.round(best * 0.97 * 100) / 100);
        }

        function applyAutoScale() {
            presentation.style.setProperty('--pres-scale', 1);
            presScale = computeAutoScale();
            presentation.style.setProperty('--pres-scale', presScale);
        }

        function bumpScale(delta) {
            autoFit = false; // l'utilisateur prend la main : plus de recalcul auto
            presScale = clampScale(Math.round((presScale + delta) * 10) / 10);
            presentation.style.setProperty('--pres-scale', presScale);
        }

        function onResize() {
            if (autoFit) { applyAutoScale(); }
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
            document.body.classList.add('presentation-active');
            showSlide(0);
            setBlack(false);
            autoFit = true;
            applyAutoScale();
            document.addEventListener('keydown', onKey, true);
            window.addEventListener('resize', onResize);
            if (presentation.requestFullscreen) {
                presentation.requestFullscreen().catch(function () {});
            }
        }

        function leave() {
            if (!document.body.classList.contains('presentation-active')) { return; }
            document.body.classList.remove('presentation-active');
            setBlack(false);
            document.removeEventListener('keydown', onKey, true);
            window.removeEventListener('resize', onResize);
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
            // Un pincer ou un glissé vient de se terminer : on n'avance pas la slide.
            if (Date.now() - lastGestureEnd < 400) { return; }
            if (black) { setBlack(false); }
            showSlide(index + 1);
        });

        /* ---------- Gestes tactiles : pincer (taille) et glisser (navigation) ---------- */
        var pinchDist = 0, pinchBase = 1, pinching = false;
        var swipeX = 0, swipeY = 0, swipeStart = 0, multiTouch = false;

        function pinchGap(touches) {
            var dx = touches[0].clientX - touches[1].clientX;
            var dy = touches[0].clientY - touches[1].clientY;
            return Math.sqrt(dx * dx + dy * dy);
        }

        function applyPinchScale(factor) {
            autoFit = false; // le pincer remplace l'auto-ajustement
            presScale = clampScale(Math.round(pinchBase * factor * 100) / 100);
            presentation.style.setProperty('--pres-scale', presScale);
        }

        presentation.addEventListener('touchstart', function (e) {
            if (e.touches.length === 2) {
                pinching = true;
                multiTouch = true;
                pinchDist = pinchGap(e.touches);
                pinchBase = presScale;
            } else if (e.touches.length === 1) {
                multiTouch = false;
                swipeX = e.touches[0].clientX;
                swipeY = e.touches[0].clientY;
                swipeStart = Date.now();
            }
        }, { passive: true });

        presentation.addEventListener('touchmove', function (e) {
            if (!pinching || e.touches.length !== 2) { return; }
            e.preventDefault(); // empêche le zoom natif de la page
            if (pinchDist > 0) {
                applyPinchScale(pinchGap(e.touches) / pinchDist);
            }
        }, { passive: false });

        presentation.addEventListener('touchend', function (e) {
            if (pinching && e.touches.length < 2) {
                pinching = false;
                lastGestureEnd = Date.now();
            }
            if (e.touches.length > 0) { return; }            // il reste des doigts posés
            if (multiTouch) { multiTouch = false; return; }  // c'était un pincer
            var t = e.changedTouches[0];
            if (!t) { return; }
            var dx = t.clientX - swipeX, dy = t.clientY - swipeY;
            // Glissé horizontal franc et rapide : vers la gauche = slide suivante.
            if (Date.now() - swipeStart < 600 && Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) {
                lastGestureEnd = Date.now();
                if (black) { setBlack(false); }
                showSlide(index + (dx < 0 ? 1 : -1));
            }
        });

        // iOS Safari : événements « gesture » dédiés (ignore touch-action).
        presentation.addEventListener('gesturestart', function (e) {
            e.preventDefault();
            pinching = true;
            multiTouch = true;
            pinchBase = presScale;
        });
        presentation.addEventListener('gesturechange', function (e) {
            if (!pinching) { return; }
            e.preventDefault();
            applyPinchScale(e.scale);
        });
        presentation.addEventListener('gestureend', function () {
            pinching = false;
            lastGestureEnd = Date.now();
        });

        document.addEventListener('fullscreenchange', function () {
            if (!document.fullscreenElement) { leave(); return; }
            // Le passage en plein écran change la taille du viewport (donc les vh).
            if (autoFit) { applyAutoScale(); }
        });

        // La police « Inter » peut arriver après coup : on recalcule une fois prête.
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(function () {
                if (autoFit && document.body.classList.contains('presentation-active')) {
                    applyAutoScale();
                }
            });
        }
    }
})();
