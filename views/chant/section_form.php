<?php

/** @var array $section @var array $feuille @var string $comportement @var ?array $stats @var array $urls @var ?array $ficheRepertoire */
use App\Csrf;
use App\SectionTypes;

$retour = '/app/feuilles/' . $section['feuille_id'];
// Le psaume s'édite comme un chant (recherche, répertoire…) tout en gardant sa
// référence de lecture — il peut être lu ou remplacé par un chant (issue #3).
$estPsaume = $comportement === 'psaume';
$estChant = in_array($comportement, ['chant', 'ordinaire', 'psaume'], true);
$apercuParoissien = in_array($comportement, ['lecture', 'evangile'], true);
?>
<div class="mb-3"><a href="<?= e($retour) ?>" class="small text-decoration-none" data-save-return><i class="bi bi-arrow-left"></i> Retour à la feuille</a></div>

<h1 class="h4 mb-1"><?= e($section['nom']) ?></h1>
<p class="text-body-secondary small">
    <?= e(format_date_fr($feuille['date_heure'])) ?> — <?= e($feuille['clocher_nom']) ?>
</p>

<form method="post" action="/app/sections/<?= $section['id'] ?>"
      class="card card-body vstack gap-3"
      data-section-form
      data-comportement="<?= e($comportement) ?>"
      data-type="<?= e($section['type']) ?>"
      data-feuille="<?= $feuille['id'] ?>"
      data-ordinaire="<?= SectionTypes::estOrdinaire($section['type']) ? '1' : '0' ?>">
    <?= Csrf::field() ?>

    <?php if ($estChant): ?>
        <?php if ($estPsaume): ?>
            <div>
                <label class="form-label" for="reference">Référence de la lecture</label>
                <input type="text" class="form-control" id="reference" name="reference" value="<?= e($section['reference']) ?>" placeholder="Ps 94 (95)">
                <div class="form-text">Conservée même si le psaume est remplacé par un chant.</div>
            </div>
        <?php endif; ?>
        <div class="position-relative">
            <label class="form-label" for="titre">Titre</label>
            <input type="text" class="form-control" id="titre" name="titre" value="<?= e($section['titre']) ?>" autocomplete="off">
            <div class="form-check mt-1">
                <input class="form-check-input" type="checkbox" id="chercher-texte" data-search-text>
                <label class="form-check-label small" for="chercher-texte">Chercher aussi dans le texte des chants</label>
            </div>
            <div class="autocomplete-panel list-group shadow-sm" data-suggestions hidden></div>
        </div>
        <?php
        // Code (cote Secli…), auteur et URL de partition ne sont plus portés par
        // la section (issue #14) : ils viennent de la fiche du répertoire liée et
        // s'affichent dans l'encadré en lecture seule ci-dessous.
        $fr = $ficheRepertoire ?? null;
        $frHote = static fn (string $url): string => (string) preg_replace(
            '/^www\./',
            '',
            parse_url($url, PHP_URL_HOST) ?: $url
        );
        $frPartitions = [];
        foreach ($urls ?? [] as $u) {
            if (!empty($u['url'])) {
                $frPartitions[$u['url']] = $frHote((string) $u['url']);
            }
        }
        ?>
        <input type="hidden" name="repertoire_id" value="<?= e((string) ($section['repertoire_id'] ?? '')) ?>" data-repertoire-field>

        <div class="border rounded p-3 small bg-body-tertiary" data-fiche-repertoire<?= $fr ? '' : ' hidden' ?>>
            <div class="fw-semibold">
                <i class="bi bi-journal-bookmark"></i> <span data-fr-titre><?= e((string) ($fr['titre'] ?? '')) ?></span>
            </div>
            <div class="text-body-secondary" data-fr-meta>
                <span data-fr-code<?= !empty($fr['code']) ? '' : ' hidden' ?>><?= e((string) ($fr['code'] ?? '')) ?></span>
                <span data-fr-sep<?= !empty($fr['code']) && !empty($fr['auteur']) ? '' : ' hidden' ?>> · </span>
                <span data-fr-auteur<?= !empty($fr['auteur']) ? '' : ' hidden' ?>><?= e((string) ($fr['auteur'] ?? '')) ?></span>
            </div>
            <div class="mt-1" data-fr-partitions<?= $frPartitions !== [] ? '' : ' hidden' ?>>
                <i class="bi bi-link-45deg"></i>
                <span data-fr-partitions-label>Partition<?= count($frPartitions) > 1 ? 's' : '' ?></span> :
                <span data-fr-partitions-liste><?php $i = 0;
                foreach ($frPartitions as $purl => $phote): echo $i++ ? ', ' : ''; ?><a href="<?= e($purl) ?>" target="_blank" rel="noopener noreferrer"><?= e($phote) ?></a><?php endforeach; ?></span>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-2">
                <a class="btn btn-sm btn-outline-secondary" data-fr-ouvrir target="_blank" rel="noopener noreferrer"
                   href="/app/repertoire/<?= (int) ($fr['id'] ?? 0) ?>"><i class="bi bi-box-arrow-up-right"></i> Ouvrir dans le répertoire</a>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-fr-recharger>
                    <i class="bi bi-arrow-clockwise"></i> Recharger le chant depuis le répertoire
                </button>
            </div>
        </div>
        <div>
            <label class="form-label" for="chant"><?= $estPsaume ? 'Texte du psaume ou du chant' : 'Texte du chant' ?></label>
            <textarea class="form-control font-monospace" id="chant" name="chant" rows="12" data-chant-input><?= e($section['chant']) ?></textarea>
            <div class="form-text">Séparez couplets et refrains par une ligne vide. Un bloc commençant par « R/ » ou « R. » est un refrain (affiché en gras).</div>
        </div>
        <div>
            <div class="form-label">Aperçu</div>
            <div class="chant-preview border rounded p-3" data-chant-preview><?= render_chant($section['chant']) ?></div>
        </div>

    <?php elseif ($comportement === 'lecture'): ?>
        <div>
            <label class="form-label" for="titre">Titre</label>
            <input type="text" class="form-control" id="titre" name="titre" value="<?= e($section['titre']) ?>">
        </div>
        <div>
            <label class="form-label" for="reference">Référence</label>
            <input type="text" class="form-control" id="reference" name="reference" value="<?= e($section['reference']) ?>" placeholder="Ez 33, 7-9">
        </div>
        <div>
            <label class="form-label" for="introduction">Introduction</label>
            <input type="text" class="form-control" id="introduction" name="introduction" value="<?= e($section['introduction']) ?>" placeholder="Lecture du livre du prophète Ézékiel">
        </div>
        <div>
            <label class="form-label" for="contenu">Contenu</label>
            <textarea class="form-control font-monospace" id="contenu" name="contenu" rows="14"><?= e($section['contenu']) ?></textarea>
            <div class="form-text">Contenu HTML issu d'AELF.</div>
        </div>

    <?php elseif ($comportement === 'evangile'): ?>
        <div>
            <label class="form-label" for="acclamation">Acclamation</label>
            <textarea class="form-control" id="acclamation" name="acclamation" rows="4"><?= e($section['acclamation']) ?></textarea>
        </div>
        <div>
            <label class="form-label" for="introduction">Phrase introductive</label>
            <input type="text" class="form-control" id="introduction" name="introduction" value="<?= e($section['introduction']) ?>" placeholder="Évangile de Jésus Christ selon saint Matthieu">
        </div>
        <div>
            <label class="form-label" for="reference">Référence</label>
            <input type="text" class="form-control" id="reference" name="reference" value="<?= e($section['reference']) ?>" placeholder="Mt 18, 15-20">
        </div>
        <div>
            <label class="form-label" for="contenu">Contenu</label>
            <textarea class="form-control font-monospace" id="contenu" name="contenu" rows="14"><?= e($section['contenu']) ?></textarea>
        </div>

    <?php else: /* priere & divers */ ?>
        <div>
            <label class="form-label" for="contenu">Contenu</label>
            <textarea class="form-control" id="contenu" name="contenu" rows="10"><?= e($section['contenu']) ?></textarea>
        </div>
    <?php endif; ?>

    <?php if ($apercuParoissien): ?>
        <div>
            <div class="form-label">Aperçu paroissien</div>
            <div class="section-apercu border rounded p-3" data-section-preview data-endpoint="/app/sections/<?= $section['id'] ?>/apercu"><?= view('public/_section', ['s' => $section]) ?></div>
            <div class="form-text">Rendu tel qu'il apparaîtra sur la feuille des paroissiens.</div>
        </div>
    <?php endif; ?>

    <div class="d-flex flex-column flex-sm-row gap-2 justify-content-sm-between">
        <div class="d-flex flex-wrap gap-2 order-last order-sm-first">
            <?php if ($estChant): ?>
                <?php
                $aRepertoire = !empty($section['repertoire_id']);
                $chantRempli = trim((string) $section['titre']) !== '' && trim((string) $section['chant']) !== '';
                ?>
                <button type="button" class="btn btn-outline-secondary" data-clear-chant>Vider</button>
                <!-- Ces boutons sont tenus à jour par le JS dès qu'on choisit / vide un chant,
                     sans attendre l'enregistrement. Ils partagent le <form> principal et le
                     redirigent via formaction (un <form> imbriqué serait invalide en HTML). -->
                <button type="submit" class="btn btn-outline-secondary" formnovalidate data-ajouter-repertoire
                        formaction="/app/sections/<?= $section['id'] ?>/ajouter-repertoire"
                        <?= !$aRepertoire && $chantRempli ? '' : 'hidden' ?>>
                    <i class="bi bi-journal-plus"></i> Ajouter au répertoire
                </button>
                <button type="submit" class="btn btn-outline-secondary" formnovalidate data-maj-repertoire
                        formaction="/app/sections/<?= $section['id'] ?>/mettre-a-jour-repertoire"
                        <?= $aRepertoire ? '' : 'hidden' ?>>
                    <i class="bi bi-journal-arrow-up"></i> Mettre à jour le répertoire
                </button>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary flex-fill" href="<?= e($retour) ?>">Annuler</a>
            <button class="btn btn-primary flex-fill">Enregistrer</button>
        </div>
    </div>
</form>

<?php if ($estChant): ?>
    <?= view('chant/_suggestions', [
        'section'  => $section,
        'lectures' => $lectures ?? [],
        'externe'  => $comportement === 'chant',
    ]) ?>
<?php endif; ?>

<?php if ($stats !== null): ?>
    <?= view('partials/stats_chant', $stats) ?>
<?php endif; ?>
