<?php

/** @var array $feuilles @var array $clochers */
use App\Auth;
use App\Csrf;
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Prochaines feuilles</h1>
    <a class="btn btn-primary" href="/app/feuilles/nouveau">+ Nouvelle feuille</a>
</div>

<?php if (!$feuilles): ?>
    <p class="text-body-secondary">Aucune feuille à venir. Créez-en une !</p>
<?php else: ?>
    <div class="list-group mb-4">
        <?php foreach ($feuilles as $f): ?>
            <?php require APP_ROOT . '/views/feuilles/_row.php'; ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div id="anciennes" data-endpoint="/app/anciennes">
    <button type="button" class="btn btn-outline-secondary btn-sm" data-toggle-anciennes>Afficher les anciennes feuilles</button>
    <div data-anciennes-list class="list-group mt-3"></div>
    <div data-anciennes-sentinel></div>
</div>

<!-- Modale de copie -->
<div class="modal fade" id="copieModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post" data-copie-form>
            <?= Csrf::field() ?>
            <div class="modal-header">
                <h5 class="modal-title">Copier la feuille</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body vstack gap-3">
                <p class="text-body-secondary small mb-0">Tous les chants sont repris ; les lectures sont recalculées pour la nouvelle date.</p>
                <div>
                    <label class="form-label" for="copie_clocher">Clocher</label>
                    <select class="form-select" id="copie_clocher" name="clocher_id" required>
                        <?php foreach ($clochers as $c): ?>
                            <option value="<?= $c['id'] ?>" data-defaut="<?= e(\App\Models\Clocher::prochaineDateParDefaut($c)->format('Y-m-d\TH:i')) ?>"><?= e($c['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="copie_date">Date et heure</label>
                    <input type="datetime-local" class="form-control" id="copie_date" name="date_heure" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                <button class="btn btn-primary">Copier</button>
            </div>
        </form>
    </div>
</div>
