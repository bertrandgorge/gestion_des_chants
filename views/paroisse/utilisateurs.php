<?php

/** @var array $users */
use App\Auth;
use App\Csrf;
?>
<h1 class="h3 mb-4">Utilisateurs</h1>

<form method="post" action="/admin/utilisateurs" class="card card-body mb-4">
    <?= Csrf::field() ?>
    <div class="row g-2 align-items-end">
        <div class="col-sm-6">
            <label class="form-label" for="email">Adresse email à inviter</label>
            <input type="email" class="form-control" id="email" name="email" required>
        </div>
        <div class="col-sm-3">
            <label class="form-label" for="type">Rôle</label>
            <select class="form-select" id="type" name="type">
                <option value="chantre">Chantre</option>
                <option value="admin">Admin</option>
            </select>
        </div>
        <div class="col-sm-3">
            <button class="btn btn-primary w-100">Inviter</button>
        </div>
    </div>
</form>

<div class="table-responsive">
    <table class="table align-middle">
        <thead><tr><th>Email</th><th>Rôle</th><th>Statut</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= e($u['email']) ?></td>
                <td>
                    <form method="post" action="/admin/utilisateurs/<?= $u['id'] ?>/role" class="d-inline">
                        <?= Csrf::field() ?>
                        <select class="form-select form-select-sm d-inline-block w-auto" name="type" onchange="this.form.submit()" <?= (int) $u['id'] === Auth::id() ? 'disabled' : '' ?>>
                            <option value="chantre" <?= $u['type'] === 'chantre' ? 'selected' : '' ?>>Chantre</option>
                            <option value="admin" <?= $u['type'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                        </select>
                    </form>
                </td>
                <td>
                    <?php if (empty($u['pass_hash'])): ?>
                        <span class="badge text-bg-warning">Invitation en attente</span>
                    <?php else: ?>
                        <span class="badge text-bg-success">Actif</span>
                    <?php endif; ?>
                </td>
                <td class="text-end">
                    <?php if (empty($u['pass_hash'])): ?>
                        <form method="post" action="/admin/utilisateurs/<?= $u['id'] ?>/relancer" class="d-inline">
                            <?= Csrf::field() ?>
                            <button class="btn btn-sm btn-outline-secondary">Relancer</button>
                        </form>
                    <?php endif; ?>
                    <?php if ((int) $u['id'] !== Auth::id()): ?>
                        <form method="post" action="/admin/utilisateurs/<?= $u['id'] ?>/supprimer" class="d-inline" onsubmit="return confirm('Supprimer cet utilisateur ?')">
                            <?= Csrf::field() ?>
                            <button class="btn btn-sm btn-outline-danger">Supprimer</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
