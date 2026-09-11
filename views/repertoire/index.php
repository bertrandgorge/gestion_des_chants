<?php

/** @var array $chants @var array $tags @var string $q @var bool $texte @var string $type */
use App\Csrf;
?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
    <h1 class="h3 mb-0">Répertoire</h1>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="card card-body h-100">
            <form method="get" action="/app/repertoire" class="vstack gap-2">
                <div class="d-flex gap-2">
                    <input type="search" class="form-control" name="q" value="<?= e($q) ?>" placeholder="Titre, code, auteur, mot-clé, type, source…">
                    <button class="btn btn-primary text-nowrap"><i class="bi bi-search"></i> Chercher</button>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="texte" value="1" id="repertoire-texte" <?= $texte ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="repertoire-texte">Chercher aussi dans le texte</label>
                </div>
            </form>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card card-body h-100">
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#ajouter-chant">
                    <i class="bi bi-plus-lg"></i> Ajouter un chant
                </button>
                <!-- Rattaché au <form id="repertoire-form"> plus bas via l'attribut form= :
                     ce bouton vit dans une autre zone du DOM que les cases à cocher qu'il soumet. -->
                <button type="submit" class="btn btn-outline-secondary" form="repertoire-form" data-fusionner-btn disabled
                        onclick="return confirm('Fusionner ces deux fiches ? La moins complète sera supprimée.')">
                    <i class="bi bi-arrow-down-up"></i> Fusionner
                </button>
                <a href="/app/repertoire/doublons" class="btn btn-outline-secondary">
                    <i class="bi bi-search-heart"></i> Rechercher les doublons
                </a>
            </div>
            <div class="collapse mt-3" id="ajouter-chant">
                <form method="post" action="/app/repertoire/importer" class="d-flex gap-2">
                    <?= Csrf::field() ?>
                    <input type="url" class="form-control" name="url" placeholder="URL d'une fiche (chantonseneglise.fr, catechisme-emmanuel.com, choralepolefontainebleau.org)" required>
                    <button class="btn btn-outline-primary text-nowrap"><i class="bi bi-cloud-download"></i> Importer</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($tags): ?>
    <div class="d-flex flex-wrap gap-2 mb-4">
        <?php foreach ($tags as $tag): ?>
            <?php $actif = $type === $tag['type']; ?>
            <a class="btn btn-sm <?= $actif ? 'btn-primary' : 'btn-outline-secondary' ?>"
               href="/app/repertoire<?= e(query_suffix(['q' => $q, 'texte' => $texte ? '1' : '', 'type' => $actif ? '' : $tag['type']])) ?>">
                <?= e($tag['nom']) ?>
                <span class="badge rounded-pill <?= $actif ? 'text-bg-light text-dark' : 'text-bg-secondary' ?>"><?= $tag['n'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!$chants): ?>
    <p class="text-body-secondary">Aucun chant trouvé.</p>
<?php else: ?>
    <?php $qs = query_suffix(['q' => $q, 'texte' => $texte ? '1' : '', 'type' => $type]); ?>
    <form method="post" action="/app/repertoire/fusionner" id="repertoire-form" data-repertoire-form>
        <?= Csrf::field() ?>
        <div class="list-group mb-4">
            <?php foreach ($chants as $c): ?>
                <div class="list-group-item d-flex align-items-center gap-3">
                    <input type="checkbox" class="form-check-input flex-shrink-0" name="ids[]" value="<?= $c['id'] ?>" data-repertoire-check>
                    <a class="flex-grow-1 text-decoration-none text-body" href="/app/repertoire/<?= $c['id'] ?><?= e($qs) ?>">
                        <span class="fw-semibold"><?= e($c['titre']) ?></span>
                        <?php if ($c['code']): ?><span class="text-body-secondary">— <?= e($c['code']) ?></span><?php endif; ?>
                        <?php if ($c['auteur']): ?><span class="text-body-secondary small"> · <?= e($c['auteur']) ?></span><?php endif; ?>
                        <div class="small">
                            <span class="badge text-bg-light border"><?= e($c['nom'] ?: $c['type']) ?></span>
                            <?php if (!empty($c['ordinaire'])): ?>
                                <span class="badge text-bg-light border"><i class="bi bi-collection"></i> <?= e($c['ordinaire']) ?></span>
                            <?php endif; ?>
                            <?php foreach (array_filter(array_map('trim', explode(',', (string) $c['mots_cles']))) as $mc): ?>
                                <span class="badge text-bg-light border"><?= e($mc) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </form>
<?php endif; ?>
