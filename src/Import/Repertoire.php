<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Code de répertoire « IEV » (Il Est Vivant), propre aux chants édités par la
 * Communauté / les Éditions de l'Emmanuel. Sert à rapprocher un même chant entre
 * les imports (catechisme-emmanuel.com ↔ chantonseneglise.fr). Fonctions pures.
 */
final class Repertoire
{
    /** L'éditeur (ou l'auteur) relève-t-il de l'Emmanuel ? */
    public static function estEmmanuel(string $editeur): bool
    {
        return stripos(Paroles::sansAccents($editeur), 'emmanuel') !== false;
    }

    /**
     * Extrait un code IEV normalisé (« IEV 19-06 ») d'un texte, ou null.
     *
     * @param bool $avecJeton true : le jeton « IEV » doit être présent (texte
     *                        libre, ex. « Esprit Saint Réf. IEV 19-06 ») ;
     *                        false : accepte aussi un code nu « 19-06 » / « (19-06) »
     *                        (source fiable, ex. la fiche catechisme-emmanuel.com).
     */
    public static function iev(string $texte, bool $avecJeton = true): ?string
    {
        if (preg_match('~IEV[\s:._-]*(\d{2,4}-\d{1,3}(?:-\d{1,3})?)~iu', $texte, $m)) {
            return 'IEV ' . $m[1];
        }
        if (!$avecJeton && preg_match('~(?<![\d/-])(\d{2}-\d{1,3}(?:-\d{1,3})?)(?![\d/-])~u', $texte, $m)) {
            return 'IEV ' . $m[1];
        }

        return null;
    }

    /** Retire un fragment « Réf. IEV 19-06 » d'un libellé de catégorie. */
    public static function sansRefIev(string $texte): string
    {
        $texte = (string) preg_replace(
            '~\s*(?:r[ée]f\.?\s*)?IEV[\s:._-]*\d{2,4}-\d{1,3}(?:-\d{1,3})?~iu',
            '',
            $texte
        );

        return trim((string) preg_replace('~\s+~u', ' ', $texte), " \t\n\r-–—.,;:");
    }
}
