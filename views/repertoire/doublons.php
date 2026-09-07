<?php

/** @var array $groupes */
use App\Csrf;
?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
    <h1 class="h3 mb-0">Doublons du répertoire</h1>
    <a href="/app/repertoire" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Retour au répertoire</a>
</div>

<?php if (!$groupes): ?>
    <p class="text-body-secondary">Aucun doublon détecté (comparaison par titre, hors ponctuation et espaces).</p>
<?php else: ?>
    <p class="text-body-secondary">
        Chants regroupés par titre identique (hors ponctuation et espaces). Cochez deux fiches à fusionner.
    </p>
    <div class="mb-3">
        <!-- Rattaché au <form id="repertoire-form"> plus bas via l'attribut form=. -->
        <button type="submit" class="btn btn-outline-secondary" form="repertoire-form" data-fusionner-btn disabled
                onclick="return confirm('Fusionner ces deux fiches ? La moins complète sera supprimée.')">
            <i class="bi bi-arrow-down-up"></i> Fusionner
        </button>
    </div>
    <form method="post" action="/app/repertoire/fusionner" id="repertoire-form" data-repertoire-form>
        <?= Csrf::field() ?>
        <?php foreach ($groupes as $groupe): ?>
            <div class="card mb-3">
                <div class="list-group list-group-flush">
                    <?php foreach ($groupe['chants'] as $c): ?>
                        <div class="list-group-item d-flex align-items-start gap-3">
                            <input type="checkbox" class="form-check-input flex-shrink-0 mt-1" name="ids[]" value="<?= $c['id'] ?>" data-repertoire-check>
                            <div class="flex-grow-1">
                                <a class="fw-semibold text-decoration-none" href="/app/repertoire/<?= $c['id'] ?>"><?= e($c['titre']) ?></a>
                                <span class="text-body-secondary small">
                                    · <?= (int) $c['nb_couplets'] ?> couplet<?= (int) $c['nb_couplets'] > 1 ? 's' : '' ?>
                                    <?php if ($c['auteur']): ?> · <?= e($c['auteur']) ?><?php endif; ?>
                                </span>
                                <?php if ($c['urls']): ?>
                                    <div class="small">
                                        <?php foreach ($c['urls'] as $u): ?>
                                            <?php if ($u['url']): ?>
                                                <a href="<?= e($u['url']) ?>" target="_blank" rel="noopener noreferrer" class="me-2"><?= e($u['source']) ?></a>
                                            <?php else: ?>
                                                <span class="text-body-secondary me-2"><?= e($u['source']) ?></span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($c['premieres_lignes']): ?>
                                    <div class="small font-monospace text-body-secondary">
                                        <?php foreach ($c['premieres_lignes'] as $ligne): ?>
                                            <?= e($ligne) ?><br>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </form>
<?php endif; ?>
