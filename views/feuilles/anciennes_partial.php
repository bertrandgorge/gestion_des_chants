<?php

/** @var array $feuilles @var ?string $nextBefore */
$mois = [1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin',
    7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre'];

$courant = $_GET['_group'] ?? null; // groupe déjà affiché côté client (passé en query)
foreach ($feuilles as $f):
    $dt = new DateTimeImmutable($f['date_heure']);
    $groupe = $dt->format('Y-m');
    if ($groupe !== $courant):
        $courant = $groupe;
        ?>
        <div class="anciennes-mois sticky-top bg-body py-2 fw-semibold text-uppercase small text-body-secondary border-bottom">
            <?= e(ucfirst($mois[(int) $dt->format('n')]) . ' ' . $dt->format('Y')) ?>
        </div>
    <?php endif; ?>
    <?php require APP_ROOT . '/views/feuilles/_row.php'; ?>
<?php endforeach; ?>

<?php if ($nextBefore !== null): ?>
    <div data-next-before="<?= e($nextBefore) ?>" data-last-group="<?= e($courant) ?>"></div>
<?php elseif (!$feuilles): ?>
    <div class="text-body-secondary small py-3">Plus aucune feuille antérieure.</div>
<?php endif; ?>
