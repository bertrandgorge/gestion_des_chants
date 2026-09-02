<?php /** @var string $paroisse @var string $lien */ ?>
<div style="font-family:Arial,sans-serif;font-size:15px;color:#222">
    <p>Bonjour,</p>
    <p>Vous avez été invité·e à rejoindre la préparation des feuilles de messe de la paroisse
        <strong><?= e($paroisse) ?></strong>.</p>
    <p>Pour activer votre compte et choisir votre mot de passe :</p>
    <p><a href="<?= e($lien) ?>" style="background:#0d6efd;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block">Activer mon compte</a></p>
    <p style="font-size:13px;color:#666">Ou copiez ce lien : <?= e($lien) ?><br>Ce lien est valable 7&nbsp;jours.</p>
</div>
