<?php

/** @var array $feuille @var array $sections */
?>
<div class="impression-colonnes">
    <header class="impression-entete">
        <div class="titre"><?= e($feuille['clocher_nom']) ?></div>
        <div class="sous-titre">
            <?= e(format_date_fr($feuille['date_heure'])) ?>
            <?php if ($feuille['semaine']): ?> · <?= e($feuille['semaine']) ?><?php endif; ?>
        </div>
    </header>

    <?php if ($sections === []): ?>
        <p class="impression-vide">Aucune section sélectionnée.</p>
    <?php else: ?>
        <?php foreach ($sections as $s): ?>
            <?php require APP_ROOT . '/views/public/_section.php'; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
