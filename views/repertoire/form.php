<?php

/** @var array $chant @var array $urls @var array $memeTitre @var array $memeOrdinaire @var array<string,string> $types @var string $retourQ @var bool $retourTexte @var string $retourType @var array $stats */
use App\Csrf;

$retour = '/app/repertoire' . query_suffix(['q' => $retourQ, 'texte' => $retourTexte ? '1' : '', 'type' => $retourType]);
?>
<div class="mb-3"><a href="<?= e($retour) ?>" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Retour au répertoire</a></div>

<h1 class="h4 mb-3"><?= e($chant['titre']) ?></h1>

<form method="post" action="/app/repertoire/<?= $chant['id'] ?>" class="card card-body vstack gap-3">
    <?= Csrf::field() ?>
    <input type="hidden" name="retour_q" value="<?= e($retourQ) ?>">
    <input type="hidden" name="retour_texte" value="<?= $retourTexte ? '1' : '' ?>">
    <input type="hidden" name="retour_type" value="<?= e($retourType) ?>">
    <!-- Fiche courante : première moitié de la paire fusionnée par les boutons « Fusionner » ci-dessous. -->
    <input type="hidden" name="ids[]" value="<?= $chant['id'] ?>">

    <div>
        <label class="form-label" for="titre">Titre</label>
        <input type="text" class="form-control" id="titre" name="titre" value="<?= e($chant['titre']) ?>" required>
    </div>
    <div class="row g-2">
        <div class="col-sm-4">
            <label class="form-label" for="code">Code(s)</label>
            <input type="text" class="form-control" id="code" name="code" value="<?= e($chant['code']) ?>">
            <div class="form-text">Séparés par des virgules.</div>
        </div>
        <div class="col-sm-4">
            <label class="form-label" for="auteur">Auteur</label>
            <input type="text" class="form-control" id="auteur" name="auteur" value="<?= e($chant['auteur']) ?>">
        </div>
        <div class="col-sm-4">
            <label class="form-label" for="type">Type</label>
            <select class="form-select" id="type" name="type" data-type-select>
                <?php foreach ($types as $slug => $nom): ?>
                    <option value="<?= e($slug) ?>" <?= $slug === $chant['type'] ? 'selected' : '' ?>><?= e($nom) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div>
        <label class="form-label" for="ordinaire">Ordinaire de messe</label>
        <input type="text" class="form-control" id="ordinaire" name="ordinaire" value="<?= e($chant['ordinaire'] ?? '') ?>" placeholder="ex. Messe de Saint Jean">
        <div class="form-text">
            Regroupe les chants (Kyrie, Gloria, Sanctus…) d'une même messe : en choisissant l'un
            d'eux sur une feuille, les autres sont proposés automatiquement pour les sections vides.
        </div>
    </div>
    <div>
        <label class="form-label" for="mots_cles">Mots-clés</label>
        <input type="text" class="form-control" id="mots_cles" name="mots_cles" value="<?= e($chant['mots_cles']) ?>">
        <div class="form-text">Séparés par des virgules (ex. « Chant d'entrée, Louange »).</div>
    </div>
    <div>
        <label class="form-label" for="chant">Texte du chant</label>
        <textarea class="form-control font-monospace" id="chant" name="chant" rows="14" data-chant-input><?= e($chant['chant']) ?></textarea>
        <div class="form-text">Séparez couplets et refrains par une ligne vide. Un bloc commençant par « R/ » ou « R. » est un refrain (affiché en gras).</div>
    </div>
    <div>
        <div class="form-label">Aperçu</div>
        <div class="chant-preview border rounded p-3" data-chant-preview><?= render_chant($chant['chant']) ?></div>
    </div>

    <?php if ($urls): ?>
        <div>
            <div class="form-label">Trouvé sur</div>
            <ul class="small mb-0 list-unstyled vstack gap-1">
                <?php foreach ($urls as $u): ?>
                    <li class="d-flex align-items-center gap-2">
                        <span>
                            <?= e($u['source']) ?> —
                            <?php if ($u['url']): ?>
                                <a href="<?= e($u['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($u['url']) ?></a>
                            <?php else: ?>
                                <span class="text-body-secondary">(pas d'URL)</span>
                            <?php endif; ?>
                        </span>
                        <?php if (count($urls) > 1): ?>
                            <!-- Même formulaire que « Enregistrer » (un <form> imbriqué serait invalide en
                                 HTML et casserait les deux) : on redirige juste sa soumission via formaction.
                                 formtarget="_blank" : la nouvelle fiche s'ouvre dans un nouvel onglet, celui-ci
                                 reste sur la fiche d'origine. -->
                            <button type="submit" class="btn btn-sm btn-outline-secondary py-0 px-1"
                                    formnovalidate
                                    formaction="/app/repertoire/<?= $chant['id'] ?>/urls/<?= rawurlencode((string) $u['source']) ?>/<?= rawurlencode((string) $u['ref']) ?>/dedoublonner"
                                    formtarget="_blank"
                                    title="Paroles identiques mais musique différente : séparer cette source dans une fiche indépendante (ouverte dans un nouvel onglet)"
                                    onclick="return confirm('« <?= e(addslashes((string) $u['source'])) ?> » deviendra une fiche indépendante du répertoire (paroles identiques mais musique différente, par exemple). Elle s\'ouvrira dans un nouvel onglet. Continuer ?')">
                                <i class="bi bi-scissors"></i> Dédoublonner
                            </button>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($memeOrdinaire): ?>
        <div>
            <div class="form-label">Autres chants de « <?= e($chant['ordinaire']) ?> »</div>
            <ul class="small mb-0 list-unstyled vstack gap-1">
                <?php foreach ($memeOrdinaire as $m): ?>
                    <li>
                        <a href="/app/repertoire/<?= $m['id'] ?>" class="text-decoration-none">
                            <span class="badge text-bg-light border"><?= e($m['nom'] ?: $m['type']) ?></span>
                            <?= e($m['titre']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($memeTitre): ?>
        <div>
            <div class="form-label">Chants du répertoire au même titre</div>
            <ul class="small mb-0 list-unstyled vstack gap-2">
                <?php foreach ($memeTitre as $m): ?>
                    <li class="d-flex align-items-start gap-2">
                        <div class="flex-grow-1">
                            <a href="/app/repertoire/<?= $m['id'] ?>" class="fw-semibold text-decoration-none"><?= e($m['titre']) ?></a>
                            <?php if ($m['code']): ?><span class="text-body-secondary">— <?= e($m['code']) ?></span><?php endif; ?>
                            <?php if ($m['urls']): ?>
                                <span class="text-body-secondary">(<?= e(implode(', ', array_column($m['urls'], 'source'))) ?>)</span>
                            <?php endif; ?>
                            <?php if ($m['premieres_lignes']): ?>
                                <div class="font-monospace text-body-secondary">
                                    <?php foreach ($m['premieres_lignes'] as $ligne): ?>
                                        <?= e($ligne) ?><br>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <!-- Même formulaire que « Enregistrer » (un <form> imbriqué serait invalide en
                             HTML et casserait les deux) : le second id de la paire vient du champ
                             caché « ids[] » (fiche courante) porté par le formulaire, le premier de
                             ce bouton (name=ids[]) — seul le bouton activé envoie sa propre paire nom/valeur. -->
                        <button type="submit" class="btn btn-sm btn-outline-secondary py-0 px-1 flex-shrink-0"
                                formnovalidate
                                formaction="/app/repertoire/fusionner"
                                name="ids[]" value="<?= $m['id'] ?>"
                                title="Fusionner ces deux fiches (la moins complète sera supprimée)"
                                onclick="return confirm('Fusionner « <?= e(addslashes($chant['titre'])) ?> » et « <?= e(addslashes($m['titre'])) ?> » ? La fiche la moins complète sera supprimée.')">
                            <i class="bi bi-arrow-down-up"></i> Fusionner
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="d-flex flex-column flex-sm-row gap-2 justify-content-sm-between">
        <!-- Même formulaire que « Enregistrer » (un <form> imbriqué serait invalide en
             HTML et casserait les deux) : on redirige juste sa soumission via formaction. -->
        <button type="submit" class="btn btn-outline-danger order-last order-sm-first" formnovalidate
                formaction="/app/repertoire/<?= $chant['id'] ?>/supprimer"
                onclick="return confirm('Supprimer « <?= e(addslashes($chant['titre'])) ?> » du répertoire ? Les chants déjà repris sur des feuilles ne seront pas supprimés.')">
            <i class="bi bi-trash3"></i> Supprimer du répertoire
        </button>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary flex-fill" href="<?= e($retour) ?>">Annuler</a>
            <button class="btn btn-primary flex-fill">Enregistrer</button>
        </div>
    </div>
</form>

<?= view('partials/stats_chant', $stats) ?>
