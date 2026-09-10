<?php

/**
 * Aide au choix affichée sous le formulaire d'édition d'une section de type
 * chant : chants déjà pris pour les mêmes lectures + propositions du site
 * choralepolefontainebleau.org (chargées en AJAX).
 *
 * @var array $section
 * @var array<int,array<string,mixed>> $lectures
 */

/** Charge utile « choisir ce chant » pour le JS (format attendu par choisirChant). */
$payload = static function (array $c): string {
    return e((string) json_encode([
        'titre'         => $c['titre'] ?? '',
        'code'          => $c['code'] ?? '',
        'auteur'        => $c['auteur'] ?? '',
        'chant'         => $c['chant'] ?? '',
        'url'           => $c['url'] ?? '',
        'repertoire_id' => isset($c['repertoire_id']) && $c['repertoire_id'] !== null ? (int) $c['repertoire_id'] : null,
        'ordinaire'     => $c['ordinaire'] ?? null,
    ], JSON_UNESCAPED_UNICODE));
};
?>
<div class="border rounded p-3 mt-3" data-aide-choix>
    <h2 class="h6 mb-3"><i class="bi bi-lightbulb"></i> Aide au choix</h2>

    <?php if ($lectures !== []): ?>
        <div class="mb-3">
            <div class="fw-semibold small mb-2">Déjà pris pour ces lectures (paroisse)</div>
            <div class="list-group overflow-auto" style="max-height:22rem">
                <?php foreach ($lectures as $c): ?>
                    <div class="list-group-item d-flex flex-wrap align-items-center gap-2">
                        <span class="flex-grow-1">
                            <span class="fw-semibold"><?= e((string) $c['titre']) ?></span>
                            <?php if ($c['code']): ?><span class="text-body-secondary"><?= e((string) $c['code']) ?></span><?php endif; ?>
                            <span class="badge text-bg-light border ms-1">
                                <i class="bi bi-calendar-event"></i>
                                <?= e(format_date_fr((string) $c['feuille_date'], false)) ?> — <?= e((string) $c['clocher_nom']) ?>
                            </span>
                        </span>
                        <?php if (!empty($c['repertoire_id'])): ?>
                            <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener noreferrer"
                               href="/app/repertoire/<?= (int) $c['repertoire_id'] ?>"><i class="bi bi-journal-bookmark"></i> Ouvrir</a>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-choisir="<?= $payload($c) ?>">Choisir</button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div data-suggestions-chant data-endpoint="/app/sections/<?= (int) $section['id'] ?>/suggestions">
        <div class="fw-semibold small mb-2">Propositions de choralepolefontainebleau.org</div>
        <p class="text-body-secondary small mb-0" data-suggestions-etat>
            <span class="spinner-border spinner-border-sm"></span> Chargement…
        </p>
        <div class="list-group overflow-auto" style="max-height:22rem" data-suggestions-liste hidden></div>
    </div>
</div>
