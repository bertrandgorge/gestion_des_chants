<?php

/** @var array $sections */
$slides = presentation_slides($sections);
if ($slides === []) {
    return;
}

$ligne = static fn (string $t): string => implode('<br>', array_map('e', explode("\n", $t)));
?>
<div id="presentation" class="presentation" role="dialog" aria-label="Mode présentation">
    <?php foreach ($slides as $i => $slide): ?>
        <section class="pres-slide<?= $i === 0 ? ' is-current' : '' ?>"<?php if (!empty($slide['couplet'])): ?> data-couplet="<?= (int) $slide['couplet'] ?>" data-couplet-total="<?= (int) $slide['couplets'] ?>"<?php endif; ?>>
            <?php if ($slide['nom'] !== ''): ?>
                <div class="pres-nom"><?= e($slide['nom']) ?></div>
            <?php endif; ?>
            <div class="pres-corps">
                <?php foreach ($slide['blocs'] as $bloc): ?>
                    <div class="pres-bloc pres-<?= e($bloc['type']) ?>"><?= $ligne($bloc['texte']) ?></div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
    <div class="pres-progress" aria-hidden="true" hidden></div>
</div>
