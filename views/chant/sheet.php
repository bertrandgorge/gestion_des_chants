<?php

/** @var array $feuille @var array $sections @var array $clochers @var array $impression */
use App\Auth;
use App\Csrf;
use App\SectionTypes;

$semaineAnnee = trim(($feuille['annee'] ? 'Année ' . $feuille['annee'] : '') . ($feuille['semaine'] ? ' · ' . $feuille['semaine'] : ''), ' ·');
?>
<div class="mb-3">
    <a href="/app" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Toutes les feuilles</a>
</div>

<div class="card card-body mb-4">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-1"><?= e($feuille['clocher_nom']) ?> — <?= e(format_date_fr($feuille['date_heure'])) ?></h1>
            <div class="text-body-secondary"><?= e($semaineAnnee) ?>
                <?php if ($feuille['couleur']): $classeCouleur = liturgie_couleur_classe($feuille['couleur']); ?><span class="badge rounded-pill text-capitalize <?= $classeCouleur ?: 'text-bg-light border' ?>"><?= e($feuille['couleur']) ?></span><?php endif; ?>
            </div>
            <div class="small text-body-secondary mt-1">
                Données AELF du <?= e($feuille['aelf_date'] ?? '—') ?>.
            </div>
        </div>
        <div class="d-flex flex-column gap-2">
            <a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= e(feuille_public_url($feuille)) ?>"><i class="bi bi-box-arrow-up-right"></i> Aperçu paroissien</a>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#impressionModal"><i class="bi bi-printer"></i> Imprimer</button>
            <form method="post" action="/app/feuilles/<?= $feuille['id'] ?>/resync" onsubmit="return confirm('Recharger les lectures depuis AELF ? Les lectures modifiées seront écrasées.')">
                <?= Csrf::field() ?>
                <button class="btn btn-sm btn-outline-secondary w-100"><i class="bi bi-arrow-repeat"></i> Resynchroniser AELF</button>
            </form>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-copie
                    data-id="<?= $feuille['id'] ?>"
                    data-defaut="<?= e((new DateTimeImmutable($feuille['date_heure']))->format('Y-m-d\TH:i')) ?>"
                    data-clocher="<?= $feuille['clocher_id'] ?>"><i class="bi bi-files"></i> Copier la feuille</button>
            <?php if ((int) $feuille['chantre_id'] === Auth::id()): ?>
                <form method="post" action="/app/feuilles/<?= $feuille['id'] ?>/supprimer" onsubmit="return confirm('Supprimer définitivement cette feuille ?')">
                    <?= Csrf::field() ?>
                    <button class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-trash3"></i> Supprimer la feuille</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require APP_ROOT . '/views/feuilles/_copie_modal.php'; ?>
<?php require APP_ROOT . '/views/feuilles/_impression_modal.php'; ?>

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
            <span class="drag-handle text-body-secondary" title="Glisser pour réorganiser" style="cursor:grab"><i class="bi bi-grip-vertical"></i></span>
            <a class="flex-grow-1 text-decoration-none text-body" href="/app/sections/<?= $s['id'] ?>">
                <span class="fw-semibold"><?= e($s['nom']) ?></span>
                <?php if ($resume !== ''): ?>
                    <span class="text-body-secondary">— <?= e($resume) ?></span>
                <?php else: ?>
                    <span class="badge text-bg-light border ms-1">à compléter</span>
                <?php endif; ?>
            </a>
            <button type="button" class="btn btn-sm btn-link text-danger" data-remove-section aria-label="Supprimer la section"><i class="bi bi-x-lg"></i></button>
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
