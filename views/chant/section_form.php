<?php

/** @var array $section @var array $feuille @var string $comportement */
use App\Csrf;
use App\SectionTypes;

$retour = '/app/feuilles/' . $section['feuille_id'];
$estChant = in_array($comportement, ['chant', 'ordinaire'], true);
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
        <div class="position-relative">
            <label class="form-label" for="titre">Titre</label>
            <input type="text" class="form-control" id="titre" name="titre" value="<?= e($section['titre']) ?>" autocomplete="off" data-search-field>
            <div class="autocomplete-panel list-group shadow-sm" data-suggestions hidden></div>
        </div>
        <div class="row g-2">
            <div class="col-sm-6">
                <label class="form-label" for="code">Code</label>
                <input type="text" class="form-control" id="code" name="code" value="<?= e($section['code']) ?>" autocomplete="off" data-search-field>
            </div>
            <div class="col-sm-6">
                <label class="form-label" for="auteur">Auteur</label>
                <input type="text" class="form-control" id="auteur" name="auteur" value="<?= e($section['auteur']) ?>">
            </div>
        </div>
        <input type="hidden" name="url" value="<?= e($section['url'] ?? '') ?>" data-url-field>
        <div class="form-text<?= empty($section['url']) ? ' d-none' : '' ?>" data-url-display>
            <i class="bi bi-link-45deg"></i>
            <a href="<?= e($section['url'] ?? '') ?>" target="_blank" rel="noopener noreferrer" data-url-link><?= e($section['url'] ?? '') ?></a>
        </div>
        <div>
            <label class="form-label" for="chant">Texte du chant</label>
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

    <?php elseif ($comportement === 'psaume'): ?>
        <div>
            <label class="form-label" for="titre">Titre</label>
            <input type="text" class="form-control" id="titre" name="titre" value="<?= e($section['titre']) ?>">
        </div>
        <div>
            <label class="form-label" for="reference">Référence</label>
            <input type="text" class="form-control" id="reference" name="reference" value="<?= e($section['reference']) ?>" placeholder="Ps 94 (95)">
        </div>
        <div>
            <label class="form-label" for="chant">Texte du psaume</label>
            <textarea class="form-control font-monospace" id="chant" name="chant" rows="12" data-chant-input><?= e($section['chant']) ?></textarea>
            <div class="form-text">Refrain préfixé par « R/ », strophes séparées par une ligne vide.</div>
        </div>
        <div>
            <div class="form-label">Aperçu</div>
            <div class="chant-preview border rounded p-3" data-chant-preview><?= render_chant($section['chant']) ?></div>
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

    <div class="d-flex justify-content-between">
        <div>
            <?php if ($estChant): ?>
                <button type="button" class="btn btn-outline-secondary" data-clear-chant>Vider</button>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="<?= e($retour) ?>">Annuler</a>
            <button class="btn btn-primary">Enregistrer</button>
        </div>
    </div>
</form>
