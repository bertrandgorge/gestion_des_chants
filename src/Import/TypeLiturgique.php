<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Fait correspondre un libellé libre lu sur un site source (« Chant de communion »,
 * « Alléluia », un titre de chant…) à un slug de section de `App\SectionTypes::DEFAUT`.
 * Fonctions pures, communes à tous les imports.
 */
final class TypeLiturgique
{
    /** Type retenu quand le libellé ne permet aucune identification. */
    public const DEFAUT = 'entree';

    /**
     * Slug de `App\SectionTypes::DEFAUT` (entree, kyrie, gloria, psaume, evangile,
     * priere_universelle, offertoire, sanctus, anamnese, communion, envoi…) déduit
     * du libellé, ou self::DEFAUT si rien n'est reconnu.
     */
    public static function deduire(string $libelle): string
    {
        return self::motif($libelle) ?? self::DEFAUT;
    }

    /**
     * Type DEFAUT déduit des mots-clés du libellé, ou null si non reconnu.
     * (Sert aussi à savoir si le libellé désigne un vrai moment liturgique.)
     */
    public static function motif(string $libelle): ?string
    {
        $n = Paroles::sansAccents(mb_strtolower(trim($libelle), 'UTF-8'));
        if ($n === '') {
            return null;
        }

        // Ordre important : le premier motif trouvé gagne. Uniquement des slugs
        // de App\SectionTypes::DEFAUT (l'alléluia / acclamation → « evangile »,
        // l'Agneau de Dieu → « communion », faute de section dédiée).
        $regles = [
            'psaume'             => ['psaume responsorial', 'psaume'],
            'evangile'           => ['acclamation a l\'evangile', 'acclamation de l\'evangile', 'acclamation avant l\'evangile', 'alleluia', 'acclamation', 'sequence', 'verset de l\'evangile'],
            'kyrie'              => ['kyrie', 'seigneur prends pitie', 'rite penitentiel', 'penitentiel', 'demande de pardon', 'aspersion', 'preparation penitentielle'],
            'gloria'             => ['gloria', 'gloire a dieu'],
            'sanctus'            => ['sanctus', 'saint le seigneur', 'saint, le seigneur'],
            'anamnese'           => ['anamnese', 'proclamons le mystere'],
            'entree'             => ['chant d\'entree', 'd\'entree', 'rite d\'entree', 'rassemblement', 'ouverture', 'procession d\'entree'],
            'offertoire'         => ['offertoire', 'presentation des dons', 'preparation des dons', 'procession des offrandes'],
            'communion'          => ['communion', 'agneau de dieu', 'agnus', 'fraction du pain', 'fraction', 'agape'],
            'priere_universelle' => ['priere universelle', 'intercession', 'universelle'],
            'envoi'              => ['chant d\'envoi', 'd\'envoi', 'envoi', 'sortie', 'benediction finale', 'final'],
        ];

        foreach ($regles as $type => $motifs) {
            foreach ($motifs as $motif) {
                if (str_contains($n, $motif)) {
                    return $type;
                }
            }
        }

        return null;
    }

    /** Libellé affiché pour une section (colonne `chants.nom`). */
    public static function nom(string $libelle, string $type): string
    {
        // Le libellé du site est du texte libre : on ne l'utilise comme nom de
        // section que s'il désigne un vrai moment liturgique et qu'il reste court.
        $libelle = trim($libelle);
        if ($libelle !== '' && mb_strlen($libelle) <= 60 && self::motif($libelle) !== null) {
            return mb_convert_case(mb_substr($libelle, 0, 1, 'UTF-8'), MB_CASE_UPPER, 'UTF-8')
                . mb_substr($libelle, 1, null, 'UTF-8');
        }

        return \App\SectionTypes::nomDefaut($type) ?? 'Chant';
    }
}
