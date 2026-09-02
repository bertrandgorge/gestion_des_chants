<?php

/** @var array $feuilles */
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Prochaines feuilles</h1>
    <a class="btn btn-primary" href="/app/feuilles/nouveau"><i class="bi bi-plus-lg"></i> Nouvelle feuille</a>
</div>

<?php if (!$feuilles): ?>
    <p class="text-body-secondary">Aucune feuille à venir. Créez-en une !</p>
<?php else: ?>
    <div class="list-group mb-4">
        <?php foreach ($feuilles as $f): ?>
            <?php require APP_ROOT . '/views/feuilles/_row.php'; ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div id="anciennes" data-endpoint="/app/anciennes">
    <button type="button" class="btn btn-outline-secondary btn-sm" data-toggle-anciennes>Afficher les anciennes feuilles</button>
    <div data-anciennes-list class="list-group mt-3"></div>
    <div data-anciennes-sentinel></div>
</div>
