/* Interactions communes à l'interface chantre. */
(function () {
    'use strict';

    // --- Préremplissage de la date selon le clocher choisi -----------------
    function bindClocherDefault(select, dateInput) {
        if (!select || !dateInput) return;
        select.addEventListener('change', function () {
            var opt = select.options[select.selectedIndex];
            var d = opt && opt.getAttribute('data-defaut');
            if (d) dateInput.value = d;
        });
    }

    var feuilleForm = document.querySelector('[data-feuille-form]');
    if (feuilleForm) {
        bindClocherDefault(feuilleForm.querySelector('select[name="clocher_id"]'),
            feuilleForm.querySelector('input[name="date_heure"]'));
    }

    // --- Modale de copie --------------------------------------------------
    var copieModalEl = document.getElementById('copieModal');
    if (copieModalEl && window.bootstrap) {
        var copieModal = new bootstrap.Modal(copieModalEl);
        var form = copieModalEl.querySelector('[data-copie-form]');
        var clocherSel = copieModalEl.querySelector('#copie_clocher');
        var dateInput = copieModalEl.querySelector('#copie_date');
        bindClocherDefault(clocherSel, dateInput);

        document.querySelectorAll('[data-copie]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                form.action = '/app/feuilles/' + btn.getAttribute('data-id') + '/copier';
                var clocher = btn.getAttribute('data-clocher');
                if (clocher) clocherSel.value = clocher;
                var opt = clocherSel.options[clocherSel.selectedIndex];
                dateInput.value = (opt && opt.getAttribute('data-defaut')) || btn.getAttribute('data-defaut') || '';
                copieModal.show();
            });
        });
    }

    // --- Anciennes feuilles (défilement infini) --------------------------
    var box = document.getElementById('anciennes');
    if (box) {
        var endpoint = box.getAttribute('data-endpoint');
        var list = box.querySelector('[data-anciennes-list]');
        var toggle = box.querySelector('[data-toggle-anciennes]');
        var loading = false;
        var nextBefore = '';
        var lastGroup = '';
        var finished = false;
        var observer = null;

        function load() {
            if (loading || finished) return;
            loading = true;
            var url = endpoint + '?before=' + encodeURIComponent(nextBefore) + '&_group=' + encodeURIComponent(lastGroup);
            fetch(url, { headers: { 'X-Requested-With': 'fetch' } })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    var tmp = document.createElement('div');
                    tmp.innerHTML = html;
                    var marker = tmp.querySelector('[data-next-before]');
                    if (marker) {
                        nextBefore = marker.getAttribute('data-next-before');
                        lastGroup = marker.getAttribute('data-last-group') || lastGroup;
                        marker.remove();
                    } else {
                        finished = true;
                    }
                    while (tmp.firstChild) list.appendChild(tmp.firstChild);
                    loading = false;
                    if (!finished && observer) observer.observe(box.querySelector('[data-anciennes-sentinel]'));
                })
                .catch(function () { loading = false; });
        }

        toggle.addEventListener('click', function () {
            toggle.remove();
            load();
            if ('IntersectionObserver' in window) {
                observer = new IntersectionObserver(function (entries) {
                    if (entries[0].isIntersecting) load();
                });
                observer.observe(box.querySelector('[data-anciennes-sentinel]'));
            } else {
                var more = document.createElement('button');
                more.className = 'btn btn-outline-secondary btn-sm mt-3';
                more.textContent = 'Charger plus';
                more.addEventListener('click', load);
                box.appendChild(more);
            }
        });
    }
})();
