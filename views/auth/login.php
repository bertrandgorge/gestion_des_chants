<?php use App\Csrf; ?>
<p class="text-body-secondary small">Indiquez votre adresse email : vous recevrez un lien pour vous connecter.</p>
<form method="post" action="/login" class="vstack gap-3">
    <?= Csrf::field() ?>
    <div>
        <label class="form-label" for="email">Adresse email</label>
        <input type="email" class="form-control" id="email" name="email" value="<?= e(old('email')) ?>" required autofocus>
    </div>
    <button class="btn btn-primary w-100">Recevoir mon lien de connexion</button>
</form>
<div class="text-center mt-3 small">
    <a href="/register">Créer une paroisse</a>
</div>
