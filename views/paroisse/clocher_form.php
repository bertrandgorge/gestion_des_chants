<?php

/** @var array $clocher */
use App\Csrf;

$jours = jours_semaine();
?>
<h1 class="h3 mb-4">Modifier « <?= e($clocher['nom']) ?> »</h1>

<form method="post" action="/admin/clochers/<?= $clocher['id'] ?>" class="card card-body vstack gap-3" style="max-width:520px">
    <?= Csrf::field() ?>
    <div>
        <label class="form-label" for="nom">Nom</label>
        <input type="text" class="form-control" id="nom" name="nom" value="<?= e($clocher['nom']) ?>" required>
    </div>
    <div>
        <label class="form-label" for="slug">Slug</label>
        <input type="text" class="form-control" id="slug" name="slug" value="<?= e($clocher['slug']) ?>" pattern="[a-z0-9\-]+" required>
    </div>
    <div class="row g-2">
        <div class="col">
            <label class="form-label" for="jour_defaut">Jour habituel</label>
            <select class="form-select" id="jour_defaut" name="jour_defaut">
                <option value="">—</option>
                <?php foreach ($jours as $n => $label): ?>
                    <option value="<?= $n ?>" <?= (int) $clocher['jour_defaut'] === $n ? 'selected' : '' ?>><?= e(ucfirst($label)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col">
            <label class="form-label" for="heure_defaut">Heure</label>
            <input type="time" class="form-control" id="heure_defaut" name="heure_defaut" value="<?= e($clocher['heure_defaut'] ? substr((string) $clocher['heure_defaut'], 0, 5) : '') ?>">
        </div>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-primary">Enregistrer</button>
        <a class="btn btn-outline-secondary" href="/admin/clochers">Annuler</a>
    </div>
</form>
