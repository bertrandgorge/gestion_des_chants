/* Éditeur de feuille : réorganisation des sections, ajout/suppression,
   aperçu du chant, autocomplétion sur l'historique, reprise de l'ordinaire. */
(function () {
    'use strict';

    var CSRF = (document.querySelector('meta[name="csrf"]') || {}).content || '';

    function postForm(url, data) {
        var body = new URLSearchParams();
        body.set('_csrf', CSRF);
        Object.keys(data || {}).forEach(function (k) {
            if (Array.isArray(data[k])) {
                data[k].forEach(function (v) { body.append(k + '[]', v); });
            } else {
                body.set(k, data[k]);
            }
        });
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
            body: body
        }).then(function (r) { return r.json().catch(function () { return {}; }); });
    }

    function toast(message) {
        var el = document.createElement('div');
        el.className = 'gdc-toast';
        el.textContent = message;
        document.body.appendChild(el);
        setTimeout(function () { el.classList.add('show'); }, 10);
        setTimeout(function () { el.classList.remove('show'); setTimeout(function () { el.remove(); }, 300); }, 3200);
    }

    /* ---------- Rendu du texte de chant (miroir de render_chant en PHP) ---------- */
    function escapeHtml(s) {
        return s.replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function renderChant(texte) {
        texte = (texte || '').replace(/\r\n/g, '\n').trim();
        if (!texte) return '';
        return texte.split(/\n{2,}/).map(function (part) {
            part = part.trim();
            if (!part) return '';
            var isRefrain = /^\s*R\s*[/.]/.test(part);
            var body = part.split('\n').map(escapeHtml).join('<br>');
            var cls = 'chant-partie' + (isRefrain ? ' chant-refrain' : '');
            return isRefrain
                ? '<div class="' + cls + '"><strong>' + body + '</strong></div>'
                : '<div class="' + cls + '">' + body + '</div>';
        }).join('\n');
    }

    /* ---------- Page « feuille » : sections ---------- */
    var sectionsList = document.querySelector('[data-sections]');
    if (sectionsList) {
        var feuilleId = sectionsList.getAttribute('data-feuille');

        if (window.Sortable) {
            Sortable.create(sectionsList, {
                handle: '.drag-handle',
                animation: 150,
                onEnd: function () {
                    var ids = Array.prototype.map.call(
                        sectionsList.querySelectorAll('[data-section]'),
                        function (el) { return el.getAttribute('data-id'); }
                    );
                    postForm('/app/feuilles/' + feuilleId + '/sections', { action: 'reorder', ids: ids });
                }
            });
        }

        sectionsList.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-remove-section]');
            if (!btn) return;
            var item = btn.closest('[data-section]');
            if (!confirm('Supprimer la section « ' + item.querySelector('.fw-semibold').textContent + ' » ?')) return;
            postForm('/app/feuilles/' + feuilleId + '/sections', {
                action: 'remove',
                section_id: item.getAttribute('data-id')
            }).then(function (res) {
                if (res.ok) item.remove();
            });
        });

        var addForm = document.querySelector('[data-add-section]');
        if (addForm) {
            addForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var input = addForm.querySelector('input[name="nom"]');
                if (!input.value.trim()) return;
                postForm('/app/feuilles/' + feuilleId + '/sections', {
                    action: 'add', nom: input.value.trim()
                }).then(function (res) {
                    if (res.id) window.location.reload();
                });
            });
        }
    }

    /* ---------- Page « section » : aperçu + autocomplétion ---------- */
    var form = document.querySelector('[data-section-form]');
    if (form) {
        var chantInput = form.querySelector('[data-chant-input]');
        var preview = form.querySelector('[data-chant-preview]');
        if (chantInput && preview) {
            var refresh = function () { preview.innerHTML = renderChant(chantInput.value); };
            chantInput.addEventListener('input', refresh);
        }

        var clearBtn = form.querySelector('[data-clear-chant]');
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                ['titre', 'code', 'auteur', 'chant'].forEach(function (name) {
                    var f = form.querySelector('[name="' + name + '"]');
                    if (f) f.value = '';
                });
                if (preview) preview.innerHTML = '';
                var t = form.querySelector('[name="titre"]');
                if (t) t.focus();
            });
        }

        var comportement = form.getAttribute('data-comportement');
        var estOrdinaire = form.getAttribute('data-ordinaire') === '1';
        var feuille = form.getAttribute('data-feuille');
        var type = form.getAttribute('data-type');
        var panel = form.querySelector('[data-suggestions]');

        if ((comportement === 'chant' || comportement === 'ordinaire') && panel) {
            var titreField = form.querySelector('#titre');
            var codeField = form.querySelector('#code');
            var timer = null;

            function isEmptyChant() {
                var t = form.querySelector('[name="titre"]');
                var c = form.querySelector('[name="code"]');
                return !(t && t.value.trim()) && !(c && c.value.trim());
            }

            function hidePanel() { panel.hidden = true; panel.innerHTML = ''; }

            function search(q) {
                fetch('/app/chants/recherche?q=' + encodeURIComponent(q) + '&type=' + encodeURIComponent(type), {
                    headers: { 'X-Requested-With': 'fetch' }
                })
                    .then(function (r) { return r.json(); })
                    .then(function (items) {
                        if (!items.length) { hidePanel(); return; }
                        panel.innerHTML = '';
                        items.forEach(function (item) {
                            var a = document.createElement('button');
                            a.type = 'button';
                            a.className = 'list-group-item list-group-item-action';
                            var badges = (item.types || []).map(function (t) {
                                return '<span class="badge text-bg-light border ms-1">' + escapeHtml(t) + '</span>';
                            }).join('');
                            a.innerHTML = '<span class="fw-semibold">' + escapeHtml(item.titre || '(sans titre)') + '</span>'
                                + (item.code ? ' <span class="text-body-secondary">' + escapeHtml(item.code) + '</span>' : '')
                                + '<div class="small">' + badges + '</div>';
                            a.addEventListener('click', function () { choose(item); });
                            panel.appendChild(a);
                        });
                        panel.hidden = false;
                    })
                    .catch(hidePanel);
            }

            function choose(item) {
                setVal('titre', item.titre);
                setVal('code', item.code);
                setVal('auteur', item.auteur);
                setVal('chant', item.chant);
                if (chantInput && preview) preview.innerHTML = renderChant(chantInput.value);
                hidePanel();

                if (estOrdinaire && item.feuille_id) {
                    var sectionId = window.location.pathname.split('/').pop();
                    postForm('/app/sections/' + sectionId + '/reprendre-ordinaire', {
                        source_feuille_id: item.feuille_id
                    }).then(function (res) {
                        if (res.ok && res.reprises && res.reprises.length) {
                            toast('Ordinaire repris : ' + res.reprises.join(', '));
                        }
                    });
                }
            }

            function setVal(name, value) {
                var f = form.querySelector('[name="' + name + '"]');
                if (f) f.value = value || '';
            }

            function onType() {
                if (!isEmptyChant() && document.activeElement !== titreField && document.activeElement !== codeField) return;
                var q = (this.value || '').trim();
                clearTimeout(timer);
                if (q.length < 2) { hidePanel(); return; }
                // n'affiche que si le chant n'est pas déjà rempli par ailleurs
                var other = this === titreField ? codeField : titreField;
                if (other && other.value.trim()) { /* on cherche quand même */ }
                timer = setTimeout(function () { search(q); }, 250);
            }

            [titreField, codeField].forEach(function (f) {
                if (f) f.addEventListener('input', onType);
            });
            document.addEventListener('click', function (e) {
                if (!panel.contains(e.target) && e.target !== titreField && e.target !== codeField) hidePanel();
            });
        }
    }
})();
