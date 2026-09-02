<?php /** @var string $content */ ?>
<!doctype html>
<html lang="fr" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titre ?? 'Feuilles de messe') ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="auth-body">
<main class="auth-card">
    <h1 class="h4 mb-4 text-center">Feuilles de messe</h1>
    <?php require APP_ROOT . '/views/partials/flash.php'; ?>
    <?= $content ?>
</main>
<script src="/assets/js/vendor/bootstrap.bundle.min.js"></script>
</body>
</html>
