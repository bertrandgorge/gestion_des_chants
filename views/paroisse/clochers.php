<?php

/** @var array $paroisse @var array $clochers */
use App\Csrf;

$jours = jours_semaine();
?>
<h1 class="h3 mb-4">Clochers</h1>

<form method="post" action="/admin/clochers" class="card card-body mb-4">
    <?= Csrf::field() ?>
    <div class="row g-2 align-items-end">
        <div class="col-sm-4">
            <label class="form-label" for="nom">Nom</label>
            <input type="text" class="form-control" id="nom" name="nom" required>
        </div>
        <div class="col-sm-3">
            <label class="form-label" for="slug">Slug</label>
            <input type="text" class="form-control" id="slug" name="slug" placeholder="auto">
        </div>
        <div class="col-sm-3">
            <label class="form-label" for="jour_defaut">Jour habituel</label>
            <select class="form-select" id="jour_defaut" name="jour_defaut">
                <option value="">—</option>
                <?php foreach ($jours as $n => $label): ?>
                    <option value="<?= $n ?>"><?= e(ucfirst($label)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-2">
            <label class="form-label" for="heure_defaut">Heure</label>
            <input type="time" class="form-control" id="heure_defaut" name="heure_defaut">
        </div>
    </div>
    <div class="mt-3"><button class="btn btn-primary">Ajouter le clocher</button></div>
</form>

<?php if (!$clochers): ?>
    <p class="text-body-secondary">Aucun clocher pour le moment.</p>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($clochers as $c): ?>
            <?php $url = base_url('/' . $paroisse['slug'] . '/' . $c['slug']); ?>
            <div class="col-md-6">
                <div class="card card-body h-100">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h2 class="h5 mb-1"><?= e($c['nom']) ?></h2>
                            <div class="text-body-secondary small">
                                <?php if ($c['jour_defaut'] && $c['heure_defaut']): ?>
                                    <?= e(ucfirst($jours[(int) $c['jour_defaut']])) ?> à <?= e(substr((string) $c['heure_defaut'], 0, 5)) ?>
                                <?php else: ?>
                                    Pas d'horaire par défaut
                                <?php endif; ?>
                            </div>
                        </div>
                        <a class="btn btn-sm btn-outline-secondary" href="/admin/clochers/<?= $c['id'] ?>">Modifier</a>
                    </div>
                    <hr>
                    <div class="d-flex align-items-center gap-3">
                        <img src="/admin/clochers/<?= $c['id'] ?>/qrcode.svg" alt="QR code" width="110" height="110" style="background:#fff;padding:4px;border-radius:6px">
                        <div class="small">
                            <div class="text-body-secondary">URL publique</div>
                            <code class="user-select-all"><?= e($url) ?></code>
                            <div class="mt-2">
                                <a class="btn btn-sm btn-outline-primary" href="/admin/clochers/<?= $c['id'] ?>/qrcode.svg" download="qrcode-<?= e($c['slug']) ?>.svg">Télécharger le QR code</a>
                            </div>
                        </div>
                    </div>
                    <form method="post" action="/admin/clochers/<?= $c['id'] ?>/supprimer" class="mt-3" onsubmit="return confirm('Supprimer ce clocher ?')">
                        <?= Csrf::field() ?>
                        <button class="btn btn-sm btn-link text-danger p-0">Supprimer</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
