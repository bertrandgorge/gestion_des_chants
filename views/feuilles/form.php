<?php

/** @var array $clochers @var array $defaults */
use App\Csrf;
?>
<h1 class="h3 mb-4">Nouvelle feuille de messe</h1>

<?php if (!$clochers): ?>
    <div class="alert alert-warning">
        Aucun clocher n'est configuré. Demandez à un administrateur d'en ajouter un.
    </div>
<?php else: ?>
    <form method="post" action="/app/feuilles/nouveau" class="card card-body vstack gap-3" style="max-width:520px" data-feuille-form>
        <?= Csrf::field() ?>
        <div>
            <label class="form-label" for="clocher_id">Clocher</label>
            <select class="form-select" id="clocher_id" name="clocher_id" required>
                <?php foreach ($clochers as $c): ?>
                    <option value="<?= $c['id'] ?>" data-defaut="<?= e($defaults[$c['id']] ?? '') ?>"><?= e($c['nom']) ?></option>
                <?php endforeach; ?>
                <option value="autre">— Autre lieu —</option>
            </select>
        </div>
        <div data-lieu-libre hidden>
            <label class="form-label" for="lieu">Nom du lieu</label>
            <input type="text" class="form-control" id="lieu" name="lieu" maxlength="150"
                   placeholder="ex. Chapelle du camp scout">
            <div class="form-text">Une page publique et un QR code seront générés pour cette feuille.</div>
        </div>
        <div>
            <label class="form-label" for="date_heure">Date et heure de la messe</label>
            <input type="datetime-local" class="form-control" id="date_heure" name="date_heure"
                   value="<?= e($defaults[$clochers[0]['id']] ?? '') ?>" required>
            <div class="form-text">Préremplie avec la prochaine messe habituelle du clocher. Les lectures seront récupérées automatiquement (messe anticipée gérée pour le samedi soir).</div>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary">Créer la feuille</button>
            <a class="btn btn-outline-secondary" href="/app">Annuler</a>
        </div>
    </form>
<?php endif; ?>
