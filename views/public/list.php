<?php

/** @var array $clocher @var array $paroisse @var array $feuilles */
?>
<header class="feuille-cartouche">
    <div class="feuille-paroisse"><?= e($paroisse['nom']) ?></div>
    <div class="feuille-clocher"><?= e($clocher['nom']) ?></div>
</header>

<?php if (!$feuilles): ?>
    <p class="text-center text-body-secondary">Aucune feuille de messe programmée pour le moment.</p>
<?php else: ?>
    <p class="text-center text-body-secondary">Choisissez la célébration :</p>
    <div class="list-group feuille-choix">
        <?php foreach ($feuilles as $f): ?>
            <a class="list-group-item list-group-item-action" href="<?= e(feuille_public_url($f)) ?>">
                <div class="fw-semibold"><?= e(format_date_fr($f['date_heure'])) ?></div>
                <?php if ($f['semaine']): ?>
                    <div class="small text-body-secondary"><?= e($f['semaine']) ?></div>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
