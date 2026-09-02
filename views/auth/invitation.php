<?php

/** @var string $email @var string $token */
use App\Csrf;
?>
<p>Bienvenue&nbsp;! Définissez le mot de passe du compte <strong><?= e($email) ?></strong>.</p>
<form method="post" action="/invitation/<?= e($token) ?>" class="vstack gap-3">
    <?= Csrf::field() ?>
    <div>
        <label class="form-label" for="password">Mot de passe</label>
        <input type="password" class="form-control" id="password" name="password" minlength="8" required autofocus>
        <div class="form-text">8 caractères minimum.</div>
    </div>
    <button class="btn btn-primary w-100">Activer mon compte</button>
</form>
