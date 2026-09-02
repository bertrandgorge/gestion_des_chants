<?php

/** @var string $content */
use App\Auth;
use App\Csrf;

$user = Auth::user();
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
<body>
<nav class="navbar navbar-expand bg-body-tertiary border-bottom">
    <div class="container">
        <a class="navbar-brand" href="/app">Feuilles de messe</a>
        <div class="ms-auto d-flex align-items-center gap-2">
            <span class="d-none d-sm-inline text-body-secondary small"><?= e($user['email']) ?></span>
            <?php if (Auth::isAdmin()): ?>
                <a class="btn btn-sm btn-outline-secondary" href="/admin/paroisse" title="Administration de la paroisse" aria-label="Administration">&#9881;</a>
            <?php endif; ?>
            <form method="post" action="/logout" class="d-inline">
                <?= Csrf::field() ?>
                <button class="btn btn-sm btn-outline-secondary">Déconnexion</button>
            </form>
        </div>
    </div>
</nav>
<main class="container py-4">
    <?php require APP_ROOT . '/views/partials/flash.php'; ?>
    <?= $content ?>
</main>
<script src="/assets/js/vendor/bootstrap.bundle.min.js"></script>
<script src="/assets/js/vendor/sortable.min.js"></script>
<script src="/assets/js/app.js"></script>
<script src="/assets/js/editor.js"></script>
</body>
</html>
