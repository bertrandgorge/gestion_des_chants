<?php

/** @var array $feuille @var array $sections */
use App\Csrf;
use App\SectionTypes;

$semaineAnnee = trim(($feuille['annee'] ? 'Année ' . $feuille['annee'] : '') . ($feuille['semaine'] ? ' · ' . $feuille['semaine'] : ''), ' ·');
?>
<div class="mb-3">
    <a href="/app" class="small text-decoration-none">&larr; Toutes les feuilles</a>
</div>

<div class="card card-body mb-4">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-1"><?= e($feuille['clocher_nom']) ?> — <?= e(format_date_fr($feuille['date_heure'])) ?></h1>
            <div class="text-body-secondary"><?= e($semaineAnnee) ?>
                <?php if ($feuille['couleur']): ?><span class="badge rounded-pill text-bg-light border"><?= e($feuille['couleur']) ?></span><?php endif; ?>
            </div>
            <?php if ($feuille['titre_liturgique']): ?>
                <div class="fst-italic text-body-secondary"><?= e($feuille['titre_liturgique']) ?></div>
            <?php endif; ?>
            <div class="small text-body-secondary mt-1">
                Données AELF du <?= e($feuille['aelf_date'] ?? '—') ?>.
            </div>
        </div>
        <div class="d-flex flex-column gap-2">
            <a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= e(feuille_public_url($feuille)) ?>">Aperçu paroissien</a>
            <form method="post" action="/app/feuilles/<?= $feuille['id'] ?>/resync" onsubmit="return confirm('Recharger les lectures depuis AELF ? Les lectures modifiées seront écrasées.')">
                <?= Csrf::field() ?>
                <button class="btn btn-sm btn-outline-secondary w-100">Resynchroniser AELF</button>
            </form>
        </div>
    </div>
</div>

<div class="list-group mb-3" data-sections data-feuille="<?= $feuille['id'] ?>">
    <?php foreach ($sections as $s): ?>
        <?php
        $comportement = SectionTypes::comportement($s['type']);
        $resume = '';
        if (in_array($comportement, ['chant', 'ordinaire', 'psaume'], true)) {
            $resume = trim((string) $s['titre']);
            if ($s['code']) {
                $resume .= ' (' . $s['code'] . ')';
            }
        } elseif (in_array($comportement, ['lecture', 'evangile'], true)) {
            $resume = trim((string) $s['reference']);
        }
        ?>
        <div class="list-group-item d-flex align-items-center gap-2" data-section id="section-<?= $s['id'] ?>" data-id="<?= $s['id'] ?>">
            <span class="drag-handle text-body-secondary" title="Glisser pour réorganiser" style="cursor:grab">&#8942;&#8942;</span>
            <a class="flex-grow-1 text-decoration-none text-body" href="/app/sections/<?= $s['id'] ?>">
                <span class="fw-semibold"><?= e($s['nom']) ?></span>
                <?php if ($resume !== ''): ?>
                    <span class="text-body-secondary">— <?= e($resume) ?></span>
                <?php else: ?>
                    <span class="badge text-bg-light border ms-1">à compléter</span>
                <?php endif; ?>
            </a>
            <button type="button" class="btn btn-sm btn-link text-danger" data-remove-section aria-label="Supprimer la section">&times;</button>
        </div>
    <?php endforeach; ?>
</div>

<form class="row g-2 align-items-end" data-add-section data-feuille="<?= $feuille['id'] ?>" style="max-width:480px">
    <div class="col">
        <label class="form-label small" for="nouvelle_section">Ajouter une section</label>
        <input type="text" class="form-control form-control-sm" id="nouvelle_section" name="nom" placeholder="ex. Méditation" required>
    </div>
    <div class="col-auto">
        <button class="btn btn-sm btn-outline-primary">Ajouter</button>
    </div>
</form>
