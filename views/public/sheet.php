<?php

/** @var array $feuille @var array $paroisse @var array $sections */
?>
<header class="feuille-cartouche">
    <?php if (!empty($paroisse['logo'])): ?>
        <img src="/<?= e($paroisse['logo']) ?>" alt="" class="feuille-logo">
    <?php endif; ?>
    <div class="feuille-paroisse"><?= e($paroisse['nom']) ?></div>
    <div class="feuille-clocher"><?= e($feuille['clocher_nom']) ?></div>
    <h1 class="feuille-titre"><?= e(format_date_fr($feuille['date_heure'])) ?></h1>
    <?php if ($feuille['semaine']): ?>
        <div class="feuille-semaine"><?= e($feuille['semaine']) ?></div>
    <?php endif; ?>
</header>

<?php foreach ($sections as $s): ?>
    <?php require APP_ROOT . '/views/public/_section.php'; ?>
<?php endforeach; ?>

<footer class="feuille-footer text-body-secondary">
    <a href="/<?= e($paroisse['slug']) ?>/<?= e($feuille['clocher_slug']) ?>">Autres feuilles de ce clocher</a>
</footer>
