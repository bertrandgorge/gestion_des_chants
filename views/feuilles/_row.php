<?php

/** @var array $f */
$semaineAnnee = trim(($f['annee'] ? 'Année ' . $f['annee'] : '') . ($f['semaine'] ? ' · ' . $f['semaine'] : ''), ' ·');
?>
<div class="list-group-item list-group-item-action" id="feuille-<?= $f['id'] ?>">
    <div class="d-flex justify-content-between align-items-center gap-2">
        <div>
            <a class="fw-semibold text-decoration-none stretched-link" href="/app/feuilles/<?= $f['id'] ?>">
                <?= e($f['clocher_nom']) ?> — <?= e(format_date_fr($f['date_heure'])) ?>
                <?php if (!empty($f['clocher_ad_hoc'])): ?>
                    <span class="badge text-bg-light border"><i class="bi bi-geo-alt"></i> lieu ponctuel</span>
                <?php endif; ?>
            </a>
            <div class="small text-body-secondary">
                <?= e($semaineAnnee ?: 'Informations liturgiques indisponibles') ?>
                <?php if (!empty($f['couleur'])): $classeCouleur = liturgie_couleur_classe($f['couleur']); ?>
                    <span class="badge rounded-pill text-capitalize ms-1 <?= $classeCouleur ?: 'text-bg-light border' ?>"><?= e($f['couleur']) ?></span>
                <?php endif; ?>
            </div>
            <div class="small text-body-secondary">par <?= e($f['chantre_email']) ?></div>
        </div>
        <i class="bi bi-chevron-right text-body-secondary" aria-hidden="true"></i>
    </div>
</div>
