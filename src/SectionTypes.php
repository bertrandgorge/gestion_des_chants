<?php

declare(strict_types=1);

namespace App;

/**
 * Types de sections d'une feuille de messe.
 *
 * Chaque section possède :
 *  - un « type » (slug immuable) qui pilote le comportement d'édition et d'affichage ;
 *  - un « nom » (libellé affiché).
 *
 * Comportements : chant, ordinaire, lecture, psaume, evangile, priere.
 */
final class SectionTypes
{
    /** Sections créées par défaut sur une nouvelle feuille, dans l'ordre. */
    public const DEFAUT = [
        ['type' => 'entree',             'nom' => "Chant d'entrée",        'comportement' => 'chant'],
        ['type' => 'kyrie',              'nom' => 'Kyrie',                 'comportement' => 'ordinaire'],
        ['type' => 'gloria',             'nom' => 'Gloria',               'comportement' => 'ordinaire'],
        ['type' => 'premiere_lecture',   'nom' => 'Première lecture',      'comportement' => 'lecture'],
        ['type' => 'psaume',             'nom' => 'Psaume',               'comportement' => 'psaume'],
        ['type' => 'deuxieme_lecture',   'nom' => 'Deuxième lecture',      'comportement' => 'lecture'],
        ['type' => 'evangile',           'nom' => 'Évangile',            'comportement' => 'evangile'],
        ['type' => 'priere_universelle', 'nom' => 'Prière universelle',    'comportement' => 'priere'],
        ['type' => 'offertoire',         'nom' => 'Offertoire',           'comportement' => 'chant'],
        ['type' => 'sanctus',            'nom' => 'Sanctus',              'comportement' => 'ordinaire'],
        ['type' => 'anamnese',           'nom' => 'Anamnèse',             'comportement' => 'ordinaire'],
        ['type' => 'communion',          'nom' => 'Communion',           'comportement' => 'chant'],
        ['type' => 'envoi',              'nom' => "Chant d'envoi",         'comportement' => 'chant'],
    ];

    /** Comportement par type connu ; défaut « chant » pour les sections personnalisées. */
    private const COMPORTEMENTS = [
        'entree'             => 'chant',
        'kyrie'              => 'ordinaire',
        'gloria'             => 'ordinaire',
        'alleluia'           => 'ordinaire',
        'acclamation'        => 'ordinaire',
        'sanctus'            => 'ordinaire',
        'anamnese'           => 'ordinaire',
        'agnus'              => 'ordinaire',
        'premiere_lecture'   => 'lecture',
        'deuxieme_lecture'   => 'lecture',
        'psaume'             => 'psaume',
        'evangile'           => 'evangile',
        'priere_universelle' => 'priere',
        'offertoire'         => 'chant',
        'communion'          => 'chant',
        'envoi'              => 'chant',
    ];

    /** Sections qui composent l'ordinaire de la messe (reprise groupée). */
    public const ORDINAIRE = ['kyrie', 'gloria', 'alleluia', 'acclamation', 'sanctus', 'anamnese', 'agnus'];

    public static function comportement(string $type): string
    {
        return self::COMPORTEMENTS[$type] ?? 'chant';
    }

    public static function estOrdinaire(string $type): bool
    {
        return in_array($type, self::ORDINAIRE, true);
    }

    /** Fabrique un slug de type unique pour une section ajoutée manuellement. */
    public static function slugPersonnalise(string $nom, array $typesExistants): string
    {
        $base = slugify($nom);
        $slug = $base;
        $i = 2;
        while (in_array($slug, $typesExistants, true) || isset(self::COMPORTEMENTS[$slug])) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }
}
