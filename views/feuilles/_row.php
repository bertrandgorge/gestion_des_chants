<?php

/** @var array $f */
use App\Auth;
use App\Csrf;

$semaineAnnee = trim(($f['annee'] ? 'Année ' . $f['annee'] : '') . ($f['semaine'] ? ' · ' . $f['semaine'] : ''), ' ·');
?>
<div class="list-group-item" id="feuille-<?= $f['id'] ?>">
    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
        <div>
            <a class="fw-semibold text-decoration-none stretched-link-none" href="/app/feuilles/<?= $f['id'] ?>">
                <?= e($f['clocher_nom']) ?> — <?= e(format_date_fr($f['date_heure'])) ?>
            </a>
            <div class="small text-body-secondary">
                <?= e($semaineAnnee ?: 'Informations liturgiques indisponibles') ?>
                <?php if (!empty($f['couleur'])): ?>
                    <span class="badge rounded-pill text-bg-light border ms-1"><?= e($f['couleur']) ?></span>
                <?php endif; ?>
            </div>
            <?php if (!empty($f['titre_liturgique'])): ?>
                <div class="small text-body-secondary fst-italic"><?= e($f['titre_liturgique']) ?></div>
            <?php endif; ?>
            <div class="small text-body-secondary">par <?= e($f['chantre_email']) ?></div>
        </div>
        <div class="btn-group btn-group-sm">
            <a class="btn btn-outline-primary" href="/app/feuilles/<?= $f['id'] ?>">Ouvrir</a>
            <button type="button" class="btn btn-outline-secondary" data-copie
                    data-id="<?= $f['id'] ?>"
                    data-defaut="<?= e((new DateTimeImmutable($f['date_heure']))->format('Y-m-d\TH:i')) ?>"
                    data-clocher="<?= $f['clocher_id'] ?>">Copier</button>
            <?php if ((int) $f['chantre_id'] === Auth::id()): ?>
                <form method="post" action="/app/feuilles/<?= $f['id'] ?>/supprimer" onsubmit="return confirm('Supprimer définitivement cette feuille ?')">
                    <?= Csrf::field() ?>
                    <button class="btn btn-outline-danger">Supprimer</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
