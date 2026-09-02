<?php use App\Csrf; ?>
<p class="text-body-secondary small">Indiquez votre adresse email : vous recevrez un lien pour choisir un nouveau mot de passe.</p>
<form method="post" action="/mot-de-passe/oubli" class="vstack gap-3">
    <?= Csrf::field() ?>
    <div>
        <label class="form-label" for="email">Adresse email</label>
        <input type="email" class="form-control" id="email" name="email" required autofocus>
    </div>
    <button class="btn btn-primary w-100">Envoyer le lien</button>
</form>
<div class="text-center mt-3 small"><a href="/login">Retour à la connexion</a></div>
