<?php

/** @var array $paroisse */
use App\Csrf;
?>
<h1 class="h3 mb-4">Paroisse</h1>

<div class="row g-4">
    <div class="col-lg-7">
        <form method="post" action="/admin/paroisse" class="card card-body vstack gap-3">
            <?= Csrf::field() ?>
            <div>
                <label class="form-label" for="nom">Nom</label>
                <input type="text" class="form-control" id="nom" name="nom" value="<?= e($paroisse['nom']) ?>" required>
            </div>
            <div>
                <label class="form-label" for="slug">Slug (dans les URL)</label>
                <div class="input-group">
                    <span class="input-group-text"><?= e(rtrim((string) config('app.base_url'), '/')) ?>/</span>
                    <input type="text" class="form-control" id="slug" name="slug" value="<?= e($paroisse['slug']) ?>" pattern="[a-z0-9\-]+" required>
                </div>
                <div class="form-text">Lettres minuscules, chiffres et tirets. Modifier le slug change toutes les URL publiques.</div>
            </div>
            <div><button class="btn btn-primary">Enregistrer</button></div>
        </form>
    </div>
</div>
