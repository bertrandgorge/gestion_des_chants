<?php

/** @var string $content */
$theme = $theme ?? 'auto';
$scale = $scale ?? 1.3;
$bsTheme = $theme === 'sombre' ? 'dark' : ($theme === 'clair' ? 'light' : null);
$chantLiens = $chantLiens ?? false;
$hasChantLiens = false;
foreach (($sections ?? []) as $s) {
    if (!empty($s['url'])) { $hasChantLiens = true; break; }
}
?>
<!doctype html>
<html lang="fr"<?= $bsTheme ? ' data-bs-theme="' . $bsTheme . '"' : '' ?> data-theme-pref="<?= e($theme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'Feuille de messe') ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>:root{ --chant-scale: <?= number_format($scale, 2, '.', '') ?>; }</style>
    <script>
        // Applique tôt le thème « auto » selon le device (évite le flash).
        (function () {
            var pref = document.documentElement.getAttribute('data-theme-pref');
            if (pref === 'auto') {
                var dark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
            }
        })();
    </script>
</head>
<body class="public-body<?= $chantLiens ? ' show-chant-liens' : '' ?>">
<div class="public-toolbar">
    <div class="public-toolbar-groupe">
        <?php if (!empty($printUrl)): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e($printUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Imprimer" title="Imprimer"><i class="bi bi-printer"></i></a>
        <?php endif; ?>
        <?php if (!empty($presentation) && !empty($sections)): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-presentation aria-label="Mode présentation" title="Mode présentation"><i class="bi bi-projector"></i></button>
        <?php endif; ?>
    </div>
    <div class="public-toolbar-groupe">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-font="-" aria-label="Réduire le texte">A&minus;</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-font="+" aria-label="Agrandir le texte">A+</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-theme-toggle aria-label="Changer de thème"><i class="bi bi-circle-half"></i></button>
        <?php if ($hasChantLiens): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary<?= $chantLiens ? ' active' : '' ?>" data-chant-liens aria-pressed="<?= $chantLiens ? 'true' : 'false' ?>" aria-label="Afficher les liens des chants" title="Afficher les liens des chants (pour réviser les voix)"><i class="bi bi-music-note-beamed"></i></button>
        <?php endif; ?>
    </div>
</div>
<main class="public-main">
    <?= $content ?>
</main>
<?php if (!empty($presentation) && !empty($sections)): ?>
    <?php require APP_ROOT . '/views/public/_presentation.php'; ?>
<?php endif; ?>
<script src="/assets/js/viewer.js"></script>
</body>
</html>
