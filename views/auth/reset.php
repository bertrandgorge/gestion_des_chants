<?php

/** @var string $token */
use App\Csrf;
?>
<form method="post" action="/mot-de-passe/reset/<?= e($token) ?>" class="vstack gap-3">
    <?= Csrf::field() ?>
    <div>
        <label class="form-label" for="password">Nouveau mot de passe</label>
        <input type="password" class="form-control" id="password" name="password" minlength="8" required autofocus>
        <div class="form-text">8 caractères minimum.</div>
    </div>
    <button class="btn btn-primary w-100">Enregistrer</button>
</form>
