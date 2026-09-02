<?php use App\Csrf; ?>
<p class="text-body-secondary small">Créez votre compte administrateur et votre paroisse. Les autres membres seront ensuite ajoutés par invitation.</p>
<form method="post" action="/register" class="vstack gap-3">
    <?= Csrf::field() ?>
    <div>
        <label class="form-label" for="paroisse">Nom de la paroisse</label>
        <input type="text" class="form-control" id="paroisse" name="paroisse" value="<?= e(old('paroisse')) ?>" required autofocus>
    </div>
    <div>
        <label class="form-label" for="email">Votre adresse email</label>
        <input type="email" class="form-control" id="email" name="email" value="<?= e(old('email')) ?>" required>
    </div>
    <div>
        <label class="form-label" for="password">Mot de passe</label>
        <input type="password" class="form-control" id="password" name="password" minlength="8" required>
        <div class="form-text">8 caractères minimum.</div>
    </div>
    <button class="btn btn-primary w-100">Créer la paroisse</button>
</form>
<div class="text-center mt-3 small"><a href="/login">J'ai déjà un compte</a></div>
