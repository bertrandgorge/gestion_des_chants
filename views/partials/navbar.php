<?php

/**
 * Barre de navigation commune aux feuilles de messe et à l'administration.
 *
 * @var string $section  Section active : 'feuilles' ou 'admin'.
 */
use App\Auth;
use App\Csrf;

$user    = Auth::user();
$section  = $section ?? 'feuilles';
$isAdmin = Auth::isAdmin();
?>
<nav class="navbar navbar-expand bg-body-tertiary border-bottom">
    <div class="container">
        <ul class="navbar-nav gap-1">
            <li class="nav-item">
                <a class="nav-link<?= $section === 'feuilles' ? ' active' : '' ?>"
                   href="/app"<?= $section === 'feuilles' ? ' aria-current="page"' : '' ?>>Feuilles de messe</a>
            </li>
            <li class="nav-item">
                <a class="nav-link<?= $section === 'repertoire' ? ' active' : '' ?>"
                   href="/app/repertoire"<?= $section === 'repertoire' ? ' aria-current="page"' : '' ?>>Répertoire</a>
            </li>
            <li class="nav-item">
                <a class="nav-link<?= $section === 'statistiques' ? ' active' : '' ?>"
                   href="/app/statistiques"<?= $section === 'statistiques' ? ' aria-current="page"' : '' ?>>Statistiques</a>
            </li>
            <?php if ($isAdmin): ?>
                <li class="nav-item">
                    <a class="nav-link<?= $section === 'admin' ? ' active' : '' ?>"
                       href="/admin/paroisse"<?= $section === 'admin' ? ' aria-current="page"' : '' ?>>Administration</a>
                </li>
            <?php endif; ?>
        </ul>
        <div class="ms-auto d-flex align-items-center gap-2">
            <span class="d-none d-sm-inline text-body-secondary small"><?= e($user['email']) ?></span>
            <form method="post" action="/logout" class="d-inline">
                <?= Csrf::field() ?>
                <button class="btn btn-sm btn-outline-secondary">Déconnexion</button>
            </form>
        </div>
    </div>
</nav>
