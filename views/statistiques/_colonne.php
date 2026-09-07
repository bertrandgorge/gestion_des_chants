<?php

/**
 * Colonne de statistiques (paroisse courante ou toute la base), affichée deux
 * fois par views/statistiques/index.php.
 *
 * @var string $nom
 * @var array{top:array<int,array<string,mixed>>,parSection:array<string,array<int,array<string,mixed>>>,nouveaux:array<string,array<int,array<string,mixed>>>} $stats
 */
use App\SectionTypes;
?>
<div class="card h-100">
    <div class="card-header fw-semibold"><?= e($nom) ?></div>
    <div class="card-body vstack gap-4">
        <div>
            <h2 class="h6">Top 10 des chants les plus utilisés <span class="text-body-secondary fw-normal">(12 derniers mois)</span></h2>
            <?php if ($stats['top'] === []): ?>
                <p class="text-body-secondary small mb-0">Pas encore de données.</p>
            <?php else: ?>
                <ol class="ps-3 mb-0 small">
                    <?php foreach ($stats['top'] as $t): ?>
                        <li>
                            <a class="text-decoration-none" href="/app/repertoire/<?= (int) $t['repertoire_id'] ?>"><?= e($t['titre']) ?></a>
                            <span class="badge text-bg-light border"><?= e(SectionTypes::libelle((string) $t['type'])) ?></span>
                            <span class="text-body-secondary">(<?= (int) $t['utilisations'] ?>)</span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>

        <div>
            <h2 class="h6">Top 5 par section <span class="text-body-secondary fw-normal">(12 derniers mois)</span></h2>
            <?php if ($stats['parSection'] === []): ?>
                <p class="text-body-secondary small mb-0">Pas encore de données.</p>
            <?php else: ?>
                <div class="row row-cols-1 row-cols-md-2 g-3 small">
                    <?php foreach ($stats['parSection'] as $type => $chants): ?>
                        <div class="col">
                            <div class="fw-semibold"><?= e(SectionTypes::libelle((string) $type)) ?></div>
                            <ol class="ps-3 mb-0">
                                <?php foreach ($chants as $c): ?>
                                    <li>
                                        <a class="text-decoration-none" href="/app/repertoire/<?= (int) $c['repertoire_id'] ?>"><?= e($c['titre']) ?></a>
                                        <span class="text-body-secondary">(<?= (int) $c['utilisations'] ?>)</span>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div>
            <h2 class="h6">Nouveaux chants <span class="text-body-secondary fw-normal">(30 derniers jours)</span></h2>
            <?php if ($stats['nouveaux'] === []): ?>
                <p class="text-body-secondary small mb-0">Aucun nouveau chant.</p>
            <?php else: ?>
                <div class="row row-cols-1 row-cols-md-2 g-3 small">
                    <?php foreach ($stats['nouveaux'] as $type => $chants): ?>
                        <div class="col">
                            <div class="fw-semibold"><?= e(SectionTypes::libelle((string) $type)) ?></div>
                            <ul class="list-unstyled ps-1 mb-0 vstack gap-1">
                                <?php foreach ($chants as $c): ?>
                                    <li>
                                        <a class="text-decoration-none" href="/app/repertoire/<?= (int) $c['repertoire_id'] ?>"><?= e($c['titre']) ?></a>
                                        <span class="text-body-secondary">— <?= e(format_date_fr((string) $c['premiere_utilisation'], false)) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
