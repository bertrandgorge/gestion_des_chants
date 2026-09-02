<?php

/** @var string $content @var string|null $pageTitle */
?>
<!doctype html>
<html lang="fr" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'Feuille de chant') ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        /* --- Écran : feuille posée sur un fond gris, façon aperçu avant impression --- */
        body { background: #e9eaee; margin: 0; }

        .impression-toolbar {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            gap: .5rem;
            justify-content: center;
            padding: .6rem;
            background: #fff;
            border-bottom: 1px solid var(--bs-border-color);
        }

        .impression-sheet {
            width: 148mm;                 /* A5 portrait */
            box-sizing: border-box;
            margin: 1.5rem auto;
            padding: 12mm 10mm;
            background: #fff;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .15);
            font-size: 9.5pt;
            line-height: 1.32;
        }

        /* Deux colonnes : lecture puis impression sur A5 recto/verso. */
        .impression-colonnes {
            column-count: 2;
            column-gap: 8mm;
        }
        .impression-colonnes > * { break-inside: avoid-column; }

        .impression-entete {
            column-span: all;
            text-align: center;
            margin-bottom: .6rem;
            padding-bottom: .4rem;
            border-bottom: 1.5px solid var(--bs-border-color);
        }
        .impression-entete .titre { font-weight: 650; font-size: 1.1em; }
        .impression-entete .sous-titre { color: var(--bs-secondary-color); font-size: .85em; }

        .impression-sheet .feuille-section { margin: 0 0 .7rem; }
        .impression-sheet .feuille-section-titre {
            font-size: .82em;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--bs-secondary-color);
            border-bottom: 1px solid var(--bs-border-color);
            padding-bottom: .1rem;
            margin: 0 0 .3rem;
            break-after: avoid;
        }
        .impression-sheet .feuille-chant-texte .chant-partie { margin-bottom: .4rem; break-inside: avoid; }
        .impression-sheet .feuille-ref { font-style: italic; color: var(--bs-secondary-color); margin-bottom: .25rem; }
        .impression-sheet .feuille-lecture-titre { font-weight: 600; }
        .impression-sheet .feuille-lecture-contenu p { margin-bottom: .4rem; }
        .impression-sheet .feuille-acclamation { font-weight: 600; margin-bottom: .25rem; }

        .impression-vide { color: var(--bs-secondary-color); text-align: center; padding: 2rem 0; }

        @media print {
            @page { size: A5 portrait; margin: 10mm; }
            body { background: #fff; }
            .impression-toolbar { display: none; }
            .impression-sheet {
                width: auto;
                margin: 0;
                padding: 0;
                box-shadow: none;
                font-size: 10pt;
            }
        }
    </style>
</head>
<body>
<div class="impression-toolbar">
    <button type="button" class="btn btn-sm btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Imprimer</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.close()">Fermer</button>
</div>
<div class="impression-sheet">
    <?= $content ?>
</div>
</body>
</html>
