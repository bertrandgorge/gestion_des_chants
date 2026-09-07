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
        ['type' => 'priere_universelle', 'nom' => 'Prière universelle',    'comportement' => 'chant'],
        ['type' => 'offertoire',         'nom' => 'Offertoire',           'comportement' => 'chant'],
        ['type' => 'sanctus',            'nom' => 'Sanctus',              'comportement' => 'ordinaire'],
        ['type' => 'anamnese',           'nom' => 'Anamnèse',             'comportement' => 'ordinaire'],
        ['type' => 'agnus',              'nom' => 'Agnus',                'comportement' => 'ordinaire'],
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
        'priere_universelle' => 'chant',
        'offertoire'         => 'chant',
        'communion'          => 'chant',
        'envoi'              => 'chant',
    ];

    /** Sections qui composent l'ordinaire de la messe (reprise groupée). */
    public const ORDINAIRE = ['kyrie', 'gloria', 'alleluia', 'acclamation', 'sanctus', 'anamnese', 'agnus'];

    /** Comportements cochés par défaut à l'impression de la feuille de chant. */
    private const IMPRIMABLES_DEFAUT = ['chant', 'ordinaire', 'psaume'];

    /**
     * Types décochés par défaut à l'impression malgré leur comportement. La
     * prière universelle se saisit comme un chant (le refrain repris entre les
     * intentions) mais n'a pas sa place sur la feuille des chantres.
     */
    private const NON_IMPRIMABLES_DEFAUT = ['priere_universelle'];

    public static function comportement(string $type): string
    {
        return self::COMPORTEMENTS[$type] ?? 'chant';
    }

    /** Les slugs de la liste DEFAUT. @return list<string> */
    public static function typesDefaut(): array
    {
        return array_column(self::DEFAUT, 'type');
    }

    public static function estTypeDefaut(string $type): bool
    {
        return in_array($type, self::typesDefaut(), true);
    }

    /** Libellé (« nom ») associé à un type de la liste DEFAUT, ou null s'il est inconnu. */
    public static function nomDefaut(string $type): ?string
    {
        foreach (self::DEFAUT as $section) {
            if ($section['type'] === $type) {
                return $section['nom'];
            }
        }

        return null;
    }

    /**
     * Libellé d'un type de section pour l'affichage, y compris hors DEFAUT :
     * « alleluia » (type du répertoire, sans section dédiée sur une feuille —
     * voir App\Controllers\RepertoireController::typesDisponibles) et les
     * sections personnalisées, à défaut affichées telles quelles.
     */
    public static function libelle(string $type): string
    {
        if ($type === 'alleluia') {
            return 'Alléluia';
        }

        return self::nomDefaut($type) ?? ucfirst(str_replace('_', ' ', $type));
    }

    public static function estOrdinaire(string $type): bool
    {
        return in_array($type, self::ORDINAIRE, true);
    }

    /**
     * Une section de ce type est-elle cochée par défaut à l'impression ?
     * Les chants, l'ordinaire, le psaume — et donc les sections personnalisées,
     * dont le comportement par défaut est « chant » — le sont ; les lectures,
     * l'évangile et la prière universelle ne le sont pas.
     */
    public static function imprimableParDefaut(string $type): bool
    {
        if (in_array($type, self::NON_IMPRIMABLES_DEFAUT, true)) {
            return false;
        }

        return in_array(self::comportement($type), self::IMPRIMABLES_DEFAUT, true);
    }

    /**
     * Résout, pour chaque section d'une feuille, si sa case « imprimer » est
     * cochée : la préférence mémorisée de la paroisse l'emporte, sinon on
     * retombe sur le défaut par comportement.
     *
     * @param array<string,bool>             $prefs    type de section => coché (préférence paroisse)
     * @param array<int,array<string,mixed>> $sections lignes « chants » de la feuille
     * @return array<int,bool> id de section => coché
     */
    public static function selectionImpression(array $prefs, array $sections): array
    {
        $selection = [];
        foreach ($sections as $s) {
            $type = (string) $s['type'];
            $selection[(int) $s['id']] = array_key_exists($type, $prefs)
                ? (bool) $prefs[$type]
                : self::imprimableParDefaut($type);
        }

        return $selection;
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
