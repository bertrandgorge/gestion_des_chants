<?php

/**
 * @var array<string,string> $values
 * @var list<string>         $errors
 * @var string               $csrf
 * @var bool                 $writable
 */
$v = static fn (string $k): string => e($values[$k] ?? '');
?>
<!doctype html>
<html lang="fr" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Installation — Feuilles de messe</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-body-tertiary">
<main class="container py-5" style="max-width: 720px;">
    <h1 class="h3 mb-1">Installation</h1>
    <p class="text-body-secondary">
        Renseignez la base de données et l'envoi des emails. Un fichier <code>config.php</code>
        sera créé ; cette page ne sera alors plus accessible.
    </p>

    <?php if (!$writable): ?>
        <div class="alert alert-warning">
            Le dossier de l'application ne semble pas accessible en écriture : la création de
            <code>config.php</code> échouera. Donnez les droits d'écriture (temporairement) au
            dossier racine, ou créez le fichier à la main d'après <code>config.php.example</code>.
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="/install" class="vstack gap-4">
        <?= $csrf ?>

        <section class="card card-body">
            <h2 class="h5">Base de données</h2>
            <div class="row g-3">
                <div class="col-sm-8">
                    <label class="form-label" for="db_host">Hôte</label>
                    <input type="text" class="form-control" id="db_host" name="db_host" value="<?= $v('db_host') ?>" required>
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="db_name">Nom de la base</label>
                    <input type="text" class="form-control" id="db_name" name="db_name" value="<?= $v('db_name') ?>" required>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="db_user">Utilisateur</label>
                    <input type="text" class="form-control" id="db_user" name="db_user" value="<?= $v('db_user') ?>" required>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="db_pass">Mot de passe</label>
                    <input type="password" class="form-control" id="db_pass" name="db_pass" value="<?= $v('db_pass') ?>">
                </div>
            </div>
            <div class="mt-3 d-flex align-items-center gap-3">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-test="/install/tester-bdd">
                    Tester la connexion
                </button>
                <span class="small" data-test-result="/install/tester-bdd"></span>
            </div>
        </section>

        <section class="card card-body">
            <h2 class="h5">Envoi des emails (SMTP)</h2>
            <div class="row g-3">
                <div class="col-sm-8">
                    <label class="form-label" for="smtp_host">Serveur SMTP</label>
                    <input type="text" class="form-control" id="smtp_host" name="smtp_host" value="<?= $v('smtp_host') ?>" required>
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="smtp_port">Port</label>
                    <input type="number" class="form-control" id="smtp_port" name="smtp_port" value="<?= $v('smtp_port') ?>" required>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="smtp_user">Utilisateur</label>
                    <input type="text" class="form-control" id="smtp_user" name="smtp_user" value="<?= $v('smtp_user') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="smtp_pass">Mot de passe</label>
                    <input type="password" class="form-control" id="smtp_pass" name="smtp_pass" value="<?= $v('smtp_pass') ?>">
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="smtp_secure">Chiffrement</label>
                    <select class="form-select" id="smtp_secure" name="smtp_secure">
                        <?php foreach (['tls' => 'STARTTLS', 'ssl' => 'SSL/TLS', '' => 'Aucun'] as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= ($values['smtp_secure'] ?? '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-8">
                    <label class="form-label" for="smtp_from">Adresse d'expéditeur</label>
                    <input type="email" class="form-control" id="smtp_from" name="smtp_from" value="<?= $v('smtp_from') ?>" placeholder="no-reply@paroisse.fr">
                </div>
                <div class="col-sm-12">
                    <label class="form-label" for="smtp_from_name">Nom affiché de l'expéditeur</label>
                    <input type="text" class="form-control" id="smtp_from_name" name="smtp_from_name" value="<?= $v('smtp_from_name') ?>">
                </div>
            </div>
            <div class="row g-3 mt-0 align-items-end">
                <div class="col-sm-8">
                    <label class="form-label" for="test_email">Envoyer un email de test à</label>
                    <input type="email" class="form-control" id="test_email" name="test_email" value="<?= $v('test_email') ?>" placeholder="vous@exemple.fr">
                </div>
                <div class="col-sm-4">
                    <button type="button" class="btn btn-outline-secondary btn-sm w-100" data-test="/install/tester-email">
                        Envoyer le test
                    </button>
                </div>
            </div>
            <span class="small mt-2" data-test-result="/install/tester-email"></span>
        </section>

        <section class="card card-body">
            <h2 class="h5">Application</h2>
            <label class="form-label" for="app_base_url">URL publique (sans slash final)</label>
            <input type="url" class="form-control" id="app_base_url" name="app_base_url" value="<?= $v('app_base_url') ?>" required>
            <div class="form-text">Sert aux liens des emails et aux QR codes des clochers.</div>
        </section>

        <div>
            <button class="btn btn-primary">Terminer l'installation</button>
        </div>
    </form>
</main>

<script>
document.querySelectorAll('[data-test]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var url = btn.getAttribute('data-test');
        var out = document.querySelector('[data-test-result="' + url + '"]');
        var form = btn.closest('form');
        out.className = 'small mt-2 text-body-secondary';
        out.textContent = 'Test en cours…';
        btn.disabled = true;

        fetch(url, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                out.className = 'small mt-2 ' + (data.ok ? 'text-success' : 'text-danger');
                out.textContent = (data.ok ? '✓ ' : '✗ ') + data.message;
            })
            .catch(function () {
                out.className = 'small mt-2 text-danger';
                out.textContent = '✗ Le test a échoué (réponse inattendue du serveur).';
            })
            .finally(function () { btn.disabled = false; });
    });
});
</script>
</body>
</html>
