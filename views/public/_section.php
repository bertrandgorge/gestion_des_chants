<?php

/** @var array $s */
use App\SectionTypes;

$comportement = SectionTypes::comportement($s['type']);
?>
<section class="feuille-section">
    <h2 class="feuille-section-titre"><?= e($s['nom']) ?></h2>

    <?php if (in_array($comportement, ['chant', 'ordinaire'], true)): ?>
        <?php if ($s['titre'] || $s['code'] || $s['auteur']): ?>
            <p class="feuille-chant-meta">
                <?php if ($s['titre']): ?><span class="fw-semibold"><?= e($s['titre']) ?></span><?php endif; ?>
                <?php if ($s['code']): ?><span class="text-body-secondary"> · <?= e($s['code']) ?></span><?php endif; ?>
                <?php if ($s['auteur']): ?><span class="text-body-secondary"> · <?= e($s['auteur']) ?></span><?php endif; ?>
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
