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

    /* ---------- Texte de chant → aperçu (page section ET fiche répertoire) ---------- */
    var chantInputGlobal = document.querySelector('[data-chant-input]');
    var previewGlobal = document.querySelector('[data-chant-preview]');
    if (chantInputGlobal && previewGlobal) {
        chantInputGlobal.addEventListener('input', function () {
            previewGlobal.innerHTML = renderChant(chantInputGlobal.value);
        });
    }

    /* ---------- Page « répertoire » : sélection à fusionner ---------- */
    // Le bouton « Fusionner » vit dans la zone outils, hors du <form> qu'il soumet
    // (rattaché via l'attribut form=), donc recherché dans tout le document.
    var repertoireForm = document.querySelector('[data-repertoire-form]');
    var fusionnerBtn = document.querySelector('[data-fusionner-btn]');
    if (repertoireForm && fusionnerBtn) {
        var checks = repertoireForm.querySelectorAll('[data-repertoire-check]');
        var updateFusionnerBtn = function () {
            var n = 0;
            checks.forEach(function (c) { if (c.checked) n++; });
            var actif = n === 2;
            fusionnerBtn.disabled = !actif;
            fusionnerBtn.classList.toggle('btn-primary', actif);
            fusionnerBtn.classList.toggle('btn-outline-secondary', !actif);
        };
        checks.forEach(function (c) { c.addEventListener('change', updateFusionnerBtn); });
    }

    /* ---------- Page « section » : aperçu + autocomplétion ---------- */
    var form = document.querySelector('[data-section-form]');
    if (form) {
        // « Retour à la feuille » : on enregistre la section avant de naviguer.
        // L'envoi du formulaire redirige déjà vers la feuille après sauvegarde.
        var saveReturn = document.querySelector('[data-save-return]');
        if (saveReturn) {
            saveReturn.addEventListener('click', function (e) {
                e.preventDefault();
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            });
        }

        var chantInput = chantInputGlobal;
        var preview = previewGlobal;

        // Aperçu paroissien fidèle (lectures / évangile) : rendu côté serveur
        // via la vue publique, rafraîchi à la frappe.
        var sectionPreview = form.querySelector('[data-section-preview]');
        if (sectionPreview) {
            var previewUrl = sectionPreview.getAttribute('data-endpoint');
            var previewTimer = null;
            var refreshSectionPreview = function () {
                fetch(previewUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
                    body: new URLSearchParams(new FormData(form))
                })
                    .then(function (r) { return r.text(); })
                    .then(function (html) { sectionPreview.innerHTML = html; })
                    .catch(function () { /* on garde l'aperçu précédent */ });
            };
            form.addEventListener('input', function () {
                clearTimeout(previewTimer);
                previewTimer = setTimeout(refreshSectionPreview, 350);
            });
        }

        function setChantUrl(url) {
            var field = form.querySelector('[data-url-field]');
            if (field) field.value = url || '';
            var display = form.querySelector('[data-url-display]');
            var link = form.querySelector('[data-url-link]');
            if (link) {
                link.href = url || '';
                link.textContent = url || '';
            }
            
            if (display) display.classList.toggle('d-none', !url);
        }

        function setRepertoireId(id) {
            var field = form.querySelector('[data-repertoire-field]');
            if (field) field.value = id || '';
            var display = form.querySelector('[data-repertoire-display]');
            if (display) display.classList.toggle('d-none', !id);
        }

        var clearBtn = form.querySelector('[data-clear-chant]');
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                ['titre', 'code', 'auteur', 'chant'].forEach(function (name) {
                    var f = form.querySelector('[name="' + name + '"]');
                    if (f) f.value = '';
                });
                setChantUrl('');
                setRepertoireId(null);
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
            var searchTextField = form.querySelector('[data-search-text]');
            var timer = null;

            function isEmptyChant() {
                var t = form.querySelector('[name="titre"]');
                var c = form.querySelector('[name="code"]');
                return !(t && t.value.trim()) && !(c && c.value.trim());
            }

            function hidePanel() { panel.hidden = true; panel.innerHTML = ''; }

            function search(q) {
                var texte = searchTextField && searchTextField.checked ? '1' : '0';
                fetch('/app/chants/recherche?q=' + encodeURIComponent(q) + '&type=' + encodeURIComponent(type) + '&feuille=' + encodeURIComponent(feuille || '') + '&texte=' + texte, {
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
                            var badges = '';
                            if (item.source_label) {
                                var estRepertoire = item.repertoire_id != null;
                                badges += '<span class="badge ms-1 ' + (estRepertoire ? 'text-bg-primary' : 'text-bg-secondary') + '">'
                                    + '<i class="bi ' + (estRepertoire ? 'bi-journal-bookmark' : 'bi-calendar-event') + '"></i> '
                                    + escapeHtml(item.source_label) + '</span>';
                            }
                            badges += (item.types || []).map(function (t) {
                                return '<span class="badge text-bg-light border ms-1">' + escapeHtml(t) + '</span>';
                            }).join('');
                            if (item.url) {
                                badges += '<span class="badge text-bg-light border ms-1"><i class="bi bi-link-45deg"></i> partition</span>';
                            }
                            if (item.ordinaire) {
                                badges += '<span class="badge text-bg-light border ms-1"><i class="bi bi-collection"></i> ' + escapeHtml(item.ordinaire) + '</span>';
                            }
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
                setChantUrl(item.url);
                setRepertoireId(item.repertoire_id);
                if (chantInput && preview) preview.innerHTML = renderChant(chantInput.value);
                hidePanel();

                if (estOrdinaire && item.ordinaire && item.repertoire_id) {
                    var sectionId = window.location.pathname.split('/').pop();
                    postForm('/app/sections/' + sectionId + '/reprendre-ordinaire', {
                        repertoire_id: item.repertoire_id
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
            if (searchTextField) {
                searchTextField.addEventListener('change', function () {
                    var q = ((titreField && titreField.value) || (codeField && codeField.value) || '').trim();
                    if (q.length >= 2) search(q);
                });
            }
            document.addEventListener('click', function (e) {
                if (!panel.contains(e.target) && e.target !== titreField && e.target !== codeField) hidePanel();
            });
        }
    }
})();
