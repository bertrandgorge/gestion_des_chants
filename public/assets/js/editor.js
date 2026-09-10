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

        function setRepertoireId(id) {
            var field = form.querySelector('[data-repertoire-field]');
            if (field) field.value = id || '';
            majBoutonsRepertoire();
        }

        // Bascule, sans attendre l'enregistrement, les boutons du bas selon
        // l'état courant : chant lié au répertoire → « Mettre à jour le
        // répertoire » ; nouveau chant renseigné → « Ajouter au répertoire ».
        function majBoutonsRepertoire() {
            var rid = (form.querySelector('[data-repertoire-field]') || {}).value || '';
            var titre = ((form.querySelector('[name="titre"]') || {}).value || '').trim();
            var chant = ((form.querySelector('[name="chant"]') || {}).value || '').trim();

            var ajouter = form.querySelector('[data-ajouter-repertoire]');
            if (ajouter) ajouter.hidden = !!rid || !titre || !chant;
            var maj = form.querySelector('[data-maj-repertoire]');
            if (maj) maj.hidden = !rid;
        }

        // Encadré « chant du répertoire » : titre canonique, code, auteur et
        // TOUTES les URL de partition de la fiche. Peint à partir d'une fiche
        // complète (endpoint /repertoire/{id}, qui porte urls[]).
        function peindreFicheRepertoire(fiche) {
            var box = form.querySelector('[data-fiche-repertoire]');
            if (!box) return;
            if (!fiche || !fiche.repertoire_id) { box.hidden = true; return; }

            var set = function (sel, txt) {
                var el = box.querySelector(sel);
                if (el) { el.textContent = txt || ''; el.hidden = !txt; }
            };
            set('[data-fr-titre]', fiche.titre);
            set('[data-fr-code]', fiche.code);
            set('[data-fr-auteur]', fiche.auteur);
            var sep = box.querySelector('[data-fr-sep]');
            if (sep) sep.hidden = !(fiche.code && fiche.auteur);

            var urls = fiche.urls && fiche.urls.length ? fiche.urls : (fiche.url ? [fiche.url] : []);
            var wrap = box.querySelector('[data-fr-partitions]');
            var liste = box.querySelector('[data-fr-partitions-liste]');
            if (wrap && liste) {
                liste.innerHTML = urls.map(function (u) {
                    var host = u;
                    try { host = new URL(u).host.replace(/^www\./, ''); } catch (e) { /* garde l'URL brute */ }
                    var a = document.createElement('a');
                    a.href = u; a.target = '_blank'; a.rel = 'noopener noreferrer'; a.textContent = host;
                    return a.outerHTML;
                }).join(', ');
                wrap.hidden = urls.length === 0;
                var label = wrap.querySelector('[data-fr-partitions-label]');
                if (label) label.textContent = 'Partition' + (urls.length > 1 ? 's' : '');
            }
            var ouvrir = box.querySelector('[data-fr-ouvrir]');
            if (ouvrir) ouvrir.href = '/app/repertoire/' + fiche.repertoire_id;
            box.hidden = false;
        }

        // Met l'encadré à jour après un choix. Si l'objet ne porte pas déjà la
        // liste des URL (cas d'une suggestion / d'un résultat de recherche), on
        // recharge la fiche complète depuis le répertoire.
        function majFicheRepertoire(item) {
            if (!item || !item.repertoire_id) { peindreFicheRepertoire(null); return; }
            if (item.urls) { peindreFicheRepertoire(item); return; }
            var sectionId = window.location.pathname.split('/').pop();
            fetch('/app/sections/' + sectionId + '/repertoire/' + item.repertoire_id, {
                headers: { 'X-Requested-With': 'fetch' }
            })
                .then(function (r) { return r.json(); })
                .then(function (res) { if (res && res.ok) peindreFicheRepertoire(res); })
                .catch(function () { /* on garde l'encadré précédent */ });
        }

        function setVal(name, value) {
            var f = form.querySelector('[name="' + name + '"]');
            if (f) f.value = value || '';
        }

        var comportement = form.getAttribute('data-comportement');
        var estOrdinaire = form.getAttribute('data-ordinaire') === '1';
        var feuille = form.getAttribute('data-feuille');
        var type = form.getAttribute('data-type');
        var panel = form.querySelector('[data-suggestions]');

        // Remplit la section à partir d'un chant (recherche, propositions du
        // site, historique des lectures). Reprend l'ordinaire au besoin.
        function choisirChant(item) {
            if (!item) return;
            setVal('titre', item.titre);
            setVal('chant', item.chant);
            setRepertoireId(item.repertoire_id);
            majFicheRepertoire(item);
            if (chantInputGlobal && previewGlobal) {
                previewGlobal.innerHTML = renderChant(chantInputGlobal.value);
            }
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

        // Frappe au clavier dans titre / paroles : le bouton « Ajouter au
        // répertoire » apparaît dès que les deux sont renseignés.
        ['[name="titre"]', '[name="chant"]'].forEach(function (sel) {
            var f = form.querySelector(sel);
            if (f) f.addEventListener('input', majBoutonsRepertoire);
        });

        var clearBtn = form.querySelector('[data-clear-chant]');
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                ['titre', 'chant'].forEach(function (name) {
                    var f = form.querySelector('[name="' + name + '"]');
                    if (f) f.value = '';
                });
                setRepertoireId(null);
                majFicheRepertoire(null);
                if (preview) preview.innerHTML = '';
                var t = form.querySelector('[name="titre"]');
                if (t) t.focus();
            });
        }

        // Encadré répertoire : recharger les paroles depuis la fiche liée.
        var rechargerBtn = form.querySelector('[data-fr-recharger]');
        if (rechargerBtn) {
            rechargerBtn.addEventListener('click', function () {
                var rid = (form.querySelector('[data-repertoire-field]') || {}).value || '';
                if (!rid) return;
                rechargerBtn.disabled = true;
                fetch('/app/sections/' + window.location.pathname.split('/').pop() + '/repertoire/' + rid, {
                    headers: { 'X-Requested-With': 'fetch' }
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        rechargerBtn.disabled = false;
                        if (res && res.ok) {
                            choisirChant(res);
                            toast('Chant rechargé depuis le répertoire.');
                        } else {
                            toast('Fiche introuvable dans le répertoire.');
                        }
                    })
                    .catch(function () { rechargerBtn.disabled = false; });
            });
        }

        // « Mettre à jour le répertoire » : confirmation avant de pousser les paroles.
        var majBtn = form.querySelector('[data-maj-repertoire]');
        if (majBtn) {
            majBtn.addEventListener('click', function (e) {
                if (!confirm('Attention, êtes-vous sûr de vouloir mettre à jour les paroles du chant dans le répertoire ?')) {
                    e.preventDefault();
                }
            });
        }

        if ((comportement === 'chant' || comportement === 'ordinaire' || comportement === 'psaume') && panel) {
            var titreField = form.querySelector('#titre');
            var searchTextField = form.querySelector('[data-search-text]');
            var timer = null;

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
                            a.addEventListener('click', function () { choisirChant(item); hidePanel(); });
                            panel.appendChild(a);
                        });
                        panel.hidden = false;
                    })
                    .catch(hidePanel);
            }

            function onType() {
                var q = (this.value || '').trim();
                clearTimeout(timer);
                if (q.length < 2) { hidePanel(); return; }
                timer = setTimeout(function () { search(q); }, 250);
            }

            if (titreField) titreField.addEventListener('input', onType);
            if (searchTextField) {
                searchTextField.addEventListener('change', function () {
                    var q = ((titreField && titreField.value) || '').trim();
                    if (q.length >= 2) search(q);
                });
            }
            document.addEventListener('click', function (e) {
                if (!panel.contains(e.target) && e.target !== titreField) hidePanel();
            });
        }

        /* ---------- Aide au choix : historique des lectures + propositions du site ---------- */

        // Boutons « Choisir » rendus côté serveur (chants déjà pris pour ces lectures).
        document.querySelectorAll('[data-choisir]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                try { choisirChant(JSON.parse(btn.getAttribute('data-choisir'))); } catch (e) { /* ignore */ }
            });
        });

        var suggBox = document.querySelector('[data-suggestions-chant]');
        if (suggBox) {
            var suggEtat = suggBox.querySelector('[data-suggestions-etat]');
            var suggListe = suggBox.querySelector('[data-suggestions-liste]');

            var finSugg = function (message) {
                suggEtat.textContent = message;
                suggEtat.hidden = false;
                suggListe.hidden = true;
            };

            fetch(suggBox.getAttribute('data-endpoint'), { headers: { 'X-Requested-With': 'fetch' } })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.ok) { finSugg('Propositions indisponibles pour le moment.'); return; }
                    if (!res.chants || !res.chants.length) { finSugg('Aucune proposition pour cette section ce dimanche.'); return; }

                    suggListe.innerHTML = '';
                    res.chants.forEach(function (item) {
                        var row = document.createElement('div');
                        row.className = 'list-group-item d-flex flex-wrap align-items-center gap-2';

                        var libelle = '<span class="flex-grow-1"><span class="fw-semibold">'
                            + escapeHtml(item.titre || '(sans titre)') + '</span>'
                            + (item.code ? ' <span class="text-body-secondary">' + escapeHtml(item.code) + '</span>' : '')
                            + (item.repertoire_id ? '' : ' <span class="badge text-bg-light border">nouveau</span>')
                            + '</span>';

                        var lien = item.repertoire_id
                            ? '/app/repertoire/' + item.repertoire_id
                            : item.url;
                        var ouvrir = document.createElement('a');
                        ouvrir.className = 'btn btn-sm btn-outline-secondary';
                        ouvrir.target = '_blank';
                        ouvrir.rel = 'noopener noreferrer';
                        ouvrir.href = lien;
                        ouvrir.innerHTML = '<i class="bi bi-box-arrow-up-right"></i> Ouvrir';

                        var choisir = document.createElement('button');
                        choisir.type = 'button';
                        choisir.className = 'btn btn-sm btn-outline-primary';
                        choisir.textContent = 'Choisir';
                        choisir.addEventListener('click', function () {
                            if (item.repertoire_id) { choisirChant(item); return; }
                            choisir.disabled = true;
                            var sectionId = window.location.pathname.split('/').pop();
                            postForm('/app/sections/' + sectionId + '/importer-suggestion', { url: item.url })
                                .then(function (imp) {
                                    choisir.disabled = false;
                                    if (imp && imp.ok) {
                                        choisirChant(imp);
                                        toast('« ' + (imp.titre || 'Chant') + " » ajouté au répertoire et repris.");
                                    } else {
                                        toast('Import impossible : ' + ((imp && imp.error) || 'erreur'));
                                    }
                                });
                        });

                        row.innerHTML = libelle;
                        row.appendChild(ouvrir);
                        row.appendChild(choisir);
                        suggListe.appendChild(row);
                    });

                    suggEtat.hidden = true;
                    suggListe.hidden = false;
                })
                .catch(function () { finSugg('Propositions indisponibles pour le moment.'); });
        }
    }
})();
