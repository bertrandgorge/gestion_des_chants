<?php

/**
 * Zone de statistiques d'utilisation d'un chant du répertoire, dans la
 * paroisse courante. Utilisé en bas de la fiche répertoire et de l'édition
 * d'une section de feuille (chant/ordinaire) déjà liée au répertoire.
 *
 * @var array<int,array{date_heure:string,clocher_nom:string}> $dernieresMesses
 * @var int $utilisations12Mois
 * @var array<int,array{repertoire_id:int,titre:string,nom:?string,utilisations:int}> $topSection
 * @var int $repertoireId
 * @var string $labelSection
 */
?>
<div class="border rounded p-3 mt-3">
    <h2 class="h6 mb-3"><i class="bi bi-bar-chart"></i> Statistiques d'utilisation (paroisse)</h2>
    <div class="row g-3 small">
        <div class="col-md-4">
            <div class="fw-semibold mb-1">5 dernières messes</div>
            <?php if ($dernieresMesses === []): ?>
                <p class="text-body-secondary mb-0">Jamais utilisé.</p>
            <?php else: ?>
                <ul class="list-unstyled mb-0 vstack gap-1">
                    <?php foreach ($dernieresMesses as $m): ?>
                        <li><?= e(format_date_fr($m['date_heure'], false)) ?> — <?= e($m['clocher_nom']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div class="col-md-4">
            <div class="fw-semibold mb-1">Sur les 12 derniers mois</div>
            <p class="mb-0"><?= (int) $utilisations12Mois ?> utilisation<?= $utilisations12Mois > 1 ? 's' : '' ?></p>
        </div>
        <div class="col-md-4">
            <div class="fw-semibold mb-1">Top <?= count($topSection) ?> « <?= e($labelSection) ?> »</div>
            <?php if ($topSection === []): ?>
                <p class="text-body-secondary mb-0">Pas encore de données.</p>
            <?php else: ?>
                <ol class="ps-3 mb-0">
                    <?php foreach ($topSection as $t): ?>
                        <li class="<?= (int) $t['repertoire_id'] === $repertoireId ? 'fw-semibold' : '' ?>">
                            <a class="text-decoration-none" href="/app/repertoire/<?= (int) $t['repertoire_id'] ?>"><?= e($t['titre']) ?></a>
                            <span class="text-body-secondary">(<?= (int) $t['utilisations'] ?>)</span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
    </div>
</div>
