<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Cote liturgique du SECLI (« Secrétariat des Éditeurs de Chants pour la
 * Liturgie »), portée par `repertoire_chants.code`. Une cote = d'éventuelles
 * lettres de TEMPS liturgique, puis une lettre de RITE, puis un numéro :
 * « GA 14-56 » = Carême (G) + chant d'entrée (A). Un « L » final marque un
 * texte liturgique officiel (ZL, XL, AL).
 *
 * Sert à catégoriser les fiches du répertoire (issue #11) : le rite donne le
 * type de section, le temps alimente les mots-clés. Fonctions pures.
 *
 * @see https://fr.wikipedia.org/wiki/Codification_liturgique_du_SECLI
 */
final class Secli
{
    /** Lettres de rite reconnues (dernière lettre du préfixe). */
    private const RITES = ['A', 'B', 'C', 'D', 'P', 'T', 'U', 'X', 'Y', 'Z'];

    /** Rite → slug de `App\SectionTypes` — uniquement les correspondances sûres. */
    private const RITE_TYPE = [
        'A' => 'entree',
        'B' => 'offertoire',
        'D' => 'communion',
        'T' => 'envoi',
        'U' => 'alleluia',
        'Z' => 'psaume',
        // C, P, X, Y : trop ambigus → type() renvoie null.
    ];

    /** Préfixes à ne pas lire lettre à lettre. Valeur = rite exploitable, ou null. */
    private const PREFIXES_SPECIAUX = [
        'ZL' => 'Z',   // psaume — texte officiel
        'XL' => 'X',   // chant de la Parole — texte officiel
        'AL' => null,  // ordinaire de la messe (texte officiel) — pas « rite A »
    ];

    /** Lettre de temps liturgique → mot-clé. */
    private const TEMPS = [
        'E' => 'Avent',
        'F' => 'Noël',
        'G' => 'Carême',
        'H' => 'Passion',
        'I' => 'Pâques',
        'J' => 'Ascension',
        'K' => 'Pentecôte',
        'M' => 'Trinité',
        'N' => 'Baptême-Confirmation-Eucharistie',
        'O' => 'Mariage-Ordination',
        'R' => 'Réconciliation',
        'S' => 'Défunts',
        'V' => 'Vierge Marie',
        'W' => 'Saints',
    ];

    /** Préfixes de codes d'éditeurs / de recueils : jamais des cotes SECLI. */
    private const NON_COTES = ['IEV', 'DEV', 'EDIT', 'AELF'];

    /**
     * Découpe une liste de codes (« D 68-39, IEV 19-06 », « Y29-45 / A29-45 »).
     *
     * @return list<string>
     */
    public static function jetons(string $codeListe): array
    {
        return preg_split('~\s*[,/]\s*~u', trim($codeListe), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** Le jeton est-il une cote SECLI analysable ? */
    public static function estCote(string $jeton): bool
    {
        return self::analyse($jeton) !== null;
    }

    /** Lettre de rite d'un jeton (A..Z), ou null. */
    public static function rite(string $jeton): ?string
    {
        return self::analyse($jeton)['rite'] ?? null;
    }

    /**
     * Type de section (slug `App\SectionTypes`) déduit d'une liste de codes :
     * l'unique type sûr porté par les cotes, ou null si aucune — ou si plusieurs
     * cotes sûres se contredisent.
     *
     * Renvoie null dès qu'une cote désigne l'ordinaire de la messe (rite C /
     * préfixe AL) : la sous-partie (Kyrie/Gloria/Sanctus/Agnus) ne se lit pas
     * dans la cote, elle vient du libellé.
     */
    public static function type(string $codeListe): ?string
    {
        if (self::estOrdinaire($codeListe)) {
            return null;
        }

        $types = [];
        foreach (self::jetons($codeListe) as $jeton) {
            $rite = self::analyse($jeton)['rite'] ?? null;
            if ($rite !== null && isset(self::RITE_TYPE[$rite])) {
                $types[self::RITE_TYPE[$rite]] = true;
            }
        }

        return count($types) === 1 ? (string) array_key_first($types) : null;
    }

    /** Une des cotes désigne-t-elle l'ordinaire de la messe (rite C ou préfixe AL) ? */
    public static function estOrdinaire(string $codeListe): bool
    {
        foreach (self::jetons($codeListe) as $jeton) {
            $a = self::analyse($jeton);
            if ($a !== null && ($a['rite'] === 'C' || $a['prefixe'] === 'AL')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mots-clés de temps liturgique portés par les cotes (Avent, Carême…),
     * dédupliqués, dans l'ordre de première apparition.
     *
     * @return list<string>
     */
    public static function themes(string $codeListe): array
    {
        $vus = [];
        foreach (self::jetons($codeListe) as $jeton) {
            foreach (self::analyse($jeton)['temps'] ?? [] as $mot) {
                $vus[$mot] = true;
            }
        }

        return array_keys($vus);
    }

    /**
     * Décompose un jeton en préfixe / temps / rite, ou null si ce n'est pas une
     * cote SECLI.
     *
     * @return array{prefixe:string,temps:list<string>,rite:?string,officiel:bool}|null
     */
    private static function analyse(string $jeton): ?array
    {
        $j = mb_strtoupper(trim($jeton), 'UTF-8');
        $j = (string) preg_replace('~\s+~u', ' ', $j);
        $j = (string) preg_replace('~\s*-\s*~', '-', $j);

        foreach (self::NON_COTES as $prefix) {
            if (preg_match('~^' . $prefix . '[\s.:_-]*\d~', $j)) {
                return null;
            }
        }

        if (!preg_match('~^([A-Z]{1,3}) ?(\d{1,4}(?:-\d{1,4})*)$~', $j, $m)) {
            return null;
        }
        $prefixe = $brut = $m[1];

        // Préfixes spéciaux (texte officiel / ordinaire).
        if (array_key_exists($prefixe, self::PREFIXES_SPECIAUX)) {
            $reste = $prefixe === 'AL' ? '' : substr($prefixe, 0, -2); // retire rite + « L »

            return [
                'prefixe'  => $prefixe,
                'temps'    => self::tempsDe($reste),
                'rite'     => self::PREFIXES_SPECIAUX[$prefixe],
                'officiel' => true,
            ];
        }

        // Préfixe de 3 lettres : seulement s'il finit par « L » (texte officiel).
        // Écarte au passage « DEV » (D, E, V sont pourtant des lettres SECLI valides).
        $officiel = false;
        if (strlen($prefixe) === 3) {
            if (substr($prefixe, -1) !== 'L') {
                return null;
            }
            $officiel = true;
            $prefixe  = substr($prefixe, 0, -1);
        } elseif (strlen($prefixe) === 2 && substr($prefixe, -1) === 'L') {
            $officiel = true;
            $prefixe  = substr($prefixe, 0, -1);
        }

        // La dernière lettre restante est-elle un rite ?
        $rite      = null;
        $tempsPart = $prefixe;
        $derniere  = substr($prefixe, -1);
        if ($derniere !== '' && in_array($derniere, self::RITES, true)) {
            $rite      = $derniere;
            $tempsPart = substr($prefixe, 0, -1);
        }

        // Toute lettre restante doit être un temps ou un rite, sinon ce n'est
        // pas une cote SECLI (« Bonjour3 », « QZ12 »…).
        foreach ($tempsPart === '' ? [] : str_split($tempsPart) as $lettre) {
            if (!isset(self::TEMPS[$lettre]) && !in_array($lettre, self::RITES, true)) {
                return null;
            }
        }

        return [
            'prefixe'  => $brut,
            'temps'    => self::tempsDe($tempsPart),
            'rite'     => $rite,
            'officiel' => $officiel,
        ];
    }

    /** @return list<string> */
    private static function tempsDe(string $lettres): array
    {
        $out = [];
        foreach ($lettres === '' ? [] : str_split($lettres) as $lettre) {
            if (isset(self::TEMPS[$lettre])) {
                $out[self::TEMPS[$lettre]] = true;
            }
        }

        return array_keys($out);
    }
}
