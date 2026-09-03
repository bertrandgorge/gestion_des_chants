<?php

/** @var string $content */
$active = $active ?? '';
?>
<!doctype html>
<html lang="fr" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titre ?? 'Administration') ?> — Paroisse</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="d-flex flex-column min-vh-100">
<?php $section = 'admin'; require APP_ROOT . '/views/partials/navbar.php'; ?>
<main class="container py-4 flex-grow-1">
    <ul class="nav nav-pills mb-4 gap-1">
        <li class="nav-item"><a class="nav-link <?= $active === 'paroisse' ? 'active' : '' ?>" href="/admin/paroisse">Paroisse</a></li>
        <li class="nav-item"><a class="nav-link <?= $active === 'utilisateurs' ? 'active' : '' ?>" href="/admin/utilisateurs">Utilisateurs</a></li>
        <li class="nav-item"><a class="nav-link <?= $active === 'clochers' ? 'active' : '' ?>" href="/admin/clochers">Clochers</a></li>
    </ul>
    <?php require APP_ROOT . '/views/partials/flash.php'; ?>
    <?= $content ?>
</main>
<?php require APP_ROOT . '/views/partials/footer-github.php'; ?>
<script src="/assets/js/vendor/bootstrap.bundle.min.js"></script>
<script src="/assets/js/app.js"></script>
</body>
</html>
