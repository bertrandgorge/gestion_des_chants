<?php

/** @var string $content */
$theme = $theme ?? 'auto';
$scale = $scale ?? 1.0;
$bsTheme = $theme === 'sombre' ? 'dark' : ($theme === 'clair' ? 'light' : null);
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
<body class="public-body">
<div class="public-toolbar">
    <button type="button" class="btn btn-sm btn-outline-secondary" data-font="-" aria-label="Réduire le texte">A&minus;</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-font="+" aria-label="Agrandir le texte">A+</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-theme-toggle aria-label="Changer de thème"><i class="bi bi-circle-half"></i></button>
</div>
<main class="public-main">
    <?= $content ?>
</main>
<script src="/assets/js/viewer.js"></script>
</body>
</html>
