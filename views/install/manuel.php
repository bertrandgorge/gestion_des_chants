<?php

/**
 * @var string $contenu  contenu PHP à enregistrer sous config.php
 * @var string $chemin   chemin absolu attendu du fichier
 */
?>
<!doctype html>
<html lang="fr" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Installation — créer config.php</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-body-tertiary">
<main class="container py-5" style="max-width: 760px;">
    <h1 class="h3 mb-1">Dernière étape : créer <code>config.php</code></h1>
    <div class="alert alert-warning">
        La base de données est prête, mais l'application n'a pas les droits pour écrire
        <code><?= e($chemin) ?></code>.
        Créez ce fichier manuellement avec le contenu ci-dessous (FTP, gestionnaire de
        fichiers cPanel…), puis rechargez la page.
    </div>

    <div class="d-flex align-items-center gap-3 mb-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="copier">Copier le contenu</button>
        <span class="small text-success" id="copie-ok" hidden>Copié ✓</span>
    </div>

    <textarea id="contenu" class="form-control font-monospace" rows="26" readonly spellcheck="false"
              style="white-space: pre; overflow-wrap: normal; overflow-x: auto;"><?= e($contenu) ?></textarea>

    <div class="mt-4">
        <a class="btn btn-primary" href="/register">J'ai créé le fichier — continuer</a>
    </div>
    <p class="form-text mt-2">
        Tant que <code>config.php</code> n'existe pas, cette page reste accessible. Une fois
        le fichier en place, elle disparaît automatiquement.
    </p>
</main>

<script>
document.getElementById('copier').addEventListener('click', function () {
    var ta = document.getElementById('contenu');
    ta.select();
    ta.setSelectionRange(0, ta.value.length);
    var done = function () {
        var ok = document.getElementById('copie-ok');
        ok.hidden = false;
        setTimeout(function () { ok.hidden = true; }, 2000);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(ta.value).then(done, function () { document.execCommand('copy'); done(); });
    } else {
        document.execCommand('copy');
        done();
    }
});
</script>
</body>
</html>
