<?php

/** @var string $content */
use App\Csrf;
?>
<!doctype html>
<html lang="fr" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf" content="<?= e(Csrf::token()) ?>">
    <title><?= e($titre ?? 'Feuilles de messe') ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="d-flex flex-column min-vh-100">
<?php $section = $section ?? 'feuilles'; require APP_ROOT . '/views/partials/navbar.php'; ?>
<main class="container py-4 flex-grow-1">
    <?php require APP_ROOT . '/views/partials/flash.php'; ?>
    <?= $content ?>
</main>
<?php require APP_ROOT . '/views/partials/footer-github.php'; ?>
<script src="/assets/js/vendor/bootstrap.bundle.min.js"></script>
<script src="/assets/js/vendor/sortable.min.js"></script>
<script src="/assets/js/app.js"></script>
<script src="/assets/js/editor.js"></script>
</body>
</html>
