<?php use App\Csrf; ?>
<form method="post" action="/login" class="vstack gap-3">
    <?= Csrf::field() ?>
    <div>
        <label class="form-label" for="email">Adresse email</label>
        <input type="email" class="form-control" id="email" name="email" value="<?= e(old('email')) ?>" required autofocus>
    </div>
    <div>
        <label class="form-label" for="password">Mot de passe</label>
        <input type="password" class="form-control" id="password" name="password" required>
    </div>
    <button class="btn btn-primary w-100">Se connecter</button>
</form>
<div class="d-flex justify-content-between mt-3 small">
    <a href="/mot-de-passe/oubli">Mot de passe oublié ?</a>
    <a href="/register">Créer une paroisse</a>
</div>
