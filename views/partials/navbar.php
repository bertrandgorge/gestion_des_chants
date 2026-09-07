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
<nav class="navbar navbar-expand-md bg-body-tertiary border-bottom">
    <div class="container">
        <button class="navbar-toggler ms-auto" type="button" data-bs-toggle="collapse"
                data-bs-target="#navbarPrincipal" aria-controls="navbarPrincipal"
                aria-expanded="false" aria-label="Ouvrir le menu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarPrincipal">
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
            <div class="ms-md-auto d-flex flex-column flex-md-row align-items-start align-items-md-center gap-2 mt-2 mt-md-0">
                <span class="text-body-secondary small"><?= e($user['email']) ?></span>
                <form method="post" action="/logout" class="d-inline">
                    <?= Csrf::field() ?>
                    <button class="btn btn-sm btn-outline-secondary">Déconnexion</button>
                </form>
            </div>
        </div>
    </div>
</nav>
