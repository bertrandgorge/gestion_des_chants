<?php /** @var string $lien */ ?>
<div style="font-family:Arial,sans-serif;font-size:15px;color:#222">
    <p>Bonjour,</p>
    <p>Vous avez demandé la réinitialisation de votre mot de passe.</p>
    <p><a href="<?= e($lien) ?>" style="background:#0d6efd;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block">Choisir un nouveau mot de passe</a></p>
    <p style="font-size:13px;color:#666">Ou copiez ce lien : <?= e($lien) ?><br>Ce lien est valable 1&nbsp;heure. Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.</p>
</div>
