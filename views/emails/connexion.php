<?php /** @var string $lien */ ?>
<div style="font-family:Arial,sans-serif;font-size:15px;color:#222">
    <p>Bonjour,</p>
    <p>Voici votre lien de connexion aux feuilles de messe&nbsp;:</p>
    <p><a href="<?= e($lien) ?>" style="background:#0d6efd;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block">Me connecter</a></p>
    <p style="font-size:13px;color:#666">Ou copiez ce lien&nbsp;: <?= e($lien) ?><br>Ce lien est valable 30&nbsp;minutes et ne fonctionne qu'une fois. Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.</p>
</div>
