<?php

/** @var string $content */
use App\Auth;
use App\Csrf;

$active = $active ?? '';
$user = Auth::user();
?>
<!doctype html>
<html lang="fr" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titre ?? 'Administration') ?> — Paroisse</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<nav class="navbar navbar-expand bg-body-tertiary border-bottom">
    <div class="container">
        <a class="navbar-brand" href="/app" title="Retour aux feuilles">&larr; Feuilles</a>
        <span class="navbar-text fw-semibold">Administration</span>
        <div class="ms-auto d-flex align-items-center gap-2">
            <span class="d-none d-sm-inline text-body-secondary small"><?= e($user['email']) ?></span>
            <form method="post" action="/logout" class="d-inline">
                <?= Csrf::field() ?>
                <button class="btn btn-sm btn-outline-secondary">Déconnexion</button>
            </form>
        </div>
    </div>
</nav>
<main class="container py-4">
    <ul class="nav nav-pills mb-4 gap-1">
        <li class="nav-item"><a class="nav-link <?= $active === 'paroisse' ? 'active' : '' ?>" href="/admin/paroisse">Paroisse</a></li>
        <li class="nav-item"><a class="nav-link <?= $active === 'utilisateurs' ? 'active' : '' ?>" href="/admin/utilisateurs">Utilisateurs</a></li>
        <li class="nav-item"><a class="nav-link <?= $active === 'clochers' ? 'active' : '' ?>" href="/admin/clochers">Clochers</a></li>
    </ul>
    <?php require APP_ROOT . '/views/partials/flash.php'; ?>
    <?= $content ?>
</main>
<script src="/assets/js/vendor/bootstrap.bundle.min.js"></script>
<script src="/assets/js/app.js"></script>
</body>
</html>
