<?php

/** @var array $feuille @var array $sections @var array $impression */
use App\Csrf;
use App\SectionTypes;
?>
<!-- Modale d'impression : sélection des sections -->
<div class="modal fade" id="impressionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <form class="modal-content" method="post" action="/app/feuilles/<?= $feuille['id'] ?>/imprimer" target="_blank">
            <?= Csrf::field() ?>
            <div class="modal-header">
                <h5 class="modal-title">Imprimer la feuille de chant</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body vstack gap-2">
                <p class="text-body-secondary small mb-1">
                    Sélectionnez les sections à imprimer. Votre choix est mémorisé pour les prochaines feuilles.
                </p>
                <?php foreach ($sections as $s): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="sections[]"
                               value="<?= $s['id'] ?>" id="impr-<?= $s['id'] ?>"
                               <?= !empty($impression[$s['id']]) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="impr-<?= $s['id'] ?>">
                            <?= e($s['nom']) ?>
                            <?php if (!SectionTypes::estTypeDefaut($s['type'])): ?>
                                <span class="badge text-bg-light border ms-1">perso</span>
                            <?php endif; ?>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                <button class="btn btn-primary"><i class="bi bi-printer"></i> Générer la feuille</button>
            </div>
        </form>
    </div>
</div>
