<?php

/** @var array $s */
use App\Models\RepertoireChant;
use App\SectionTypes;

$comportement = SectionTypes::comportement($s['type']);

$partitionsUrls = [];
if (!empty($s['repertoire_id'])) {
    foreach (RepertoireChant::urls((int) $s['repertoire_id']) as $source) {
        if (!empty($source['url'])) {
            $partitionsUrls[$source['url']] = true;
        }
    }
}
if (!empty($s['url'])) {
    $partitionsUrls[$s['url']] = true;
}
$partitionsUrls = array_keys($partitionsUrls);
?>
<section class="feuille-section">
    <h2 class="feuille-section-titre">
        <?= e($s['nom']) ?>
    </h2>

    <?php if (in_array($comportement, ['chant', 'ordinaire'], true)): ?>
        <?php if ($partitionsUrls !== []): ?>
            <p class="feuille-chant-partitions">
                Voir les partitions :
                <?php
                $partitionsLiens = array_map(static function (string $url): string {
                    $hote = preg_replace('/^www\./', '', parse_url($url, PHP_URL_HOST) ?? $url);

                    return '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer">' . e($hote) . '</a>';
                }, $partitionsUrls);
                echo join_liste_fr($partitionsLiens);
                ?>
            </p>
        <?php endif; ?>
        <div class="feuille-chant-texte"><?= render_chant($s['chant']) ?></div>

    <?php elseif ($comportement === 'psaume'): ?>
        <?php if ($s['reference']): ?><p class="feuille-ref"><?= e($s['reference']) ?></p><?php endif; ?>
        <div class="feuille-chant-texte"><?= render_chant($s['chant']) ?></div>

    <?php elseif ($comportement === 'lecture'): ?>
        <?php if ($s['titre']): ?><p class="feuille-lecture-titre">«&nbsp;<?= e(strip_guillemets($s['titre'])) ?>&nbsp;»</p><?php endif; ?>
        <?php if ($s['introduction'] || $s['reference']): ?>
            <p class="feuille-ref"><?= e($s['introduction']) ?><?php if ($s['reference']): ?> (<?= e($s['reference']) ?>)<?php endif; ?></p>
        <?php endif; ?>
        <div class="feuille-lecture-contenu"><?= clean_html($s['contenu']) ?></div>

    <?php elseif ($comportement === 'evangile'): ?>
        <?php if ($s['acclamation']): ?>
            <div class="feuille-acclamation"><?= clean_html($s['acclamation']) ?></div>
        <?php endif; ?>
        <?php if ($s['introduction'] || $s['reference']): ?>
            <p class="feuille-ref"><?= e($s['introduction']) ?><?php if ($s['reference']): ?> (<?= e($s['reference']) ?>)<?php endif; ?></p>
        <?php endif; ?>
        <?php if ($s['titre']): ?><p class="feuille-lecture-titre">«&nbsp;<?= e(strip_guillemets($s['titre'])) ?>&nbsp;»</p><?php endif; ?>
        <div class="feuille-lecture-contenu"><?= clean_html($s['contenu']) ?></div>

    <?php else: ?>
        <div class="feuille-lecture-contenu"><?= clean_html($s['contenu']) ?: nl2br(e($s['chant'])) ?></div>
    <?php endif; ?>
</section>
