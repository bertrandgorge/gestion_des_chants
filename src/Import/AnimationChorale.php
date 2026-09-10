<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Analyse des pages « Propositions pour l'animation liturgique » du site de la
 * Chorale du Pôle Missionnaire de Fontainebleau (https://choralepolefontainebleau.org).
 *
 * Deux pages :
 *  - l'agenda (/evenements/categories/propositions-animation-liturgique/) liste
 *    les dimanches à venir et passés : « JJ/MM/AAAA | <a href="…">Titre</a> » ;
 *  - la fiche d'un dimanche (/evenements/{slug}/) propose, par grande rubrique
 *    (« Ouverture, Envoi », « Offertoire, communion… », « Refrain de PU »), une
 *    liste de chants, chacun lié à sa fiche du répertoire du même site.
 *
 * Fonctions pures (HTML → données). La récupération réseau, la mise en cache et
 * le rapprochement avec le répertoire local sont dans App\AnimationChorale.
 */
final class AnimationChorale
{
    public const BASE_URL = ChoralePoleFontainebleau::BASE_URL;

    public const AGENDA_URL = self::BASE_URL . '/evenements/categories/propositions-animation-liturgique/';

    /**
     * Rubriques de la fiche d'un dimanche, dans l'ordre d'apparition, avec le
     * motif qui repère leur titre dans le HTML.
     */
    private const RUBRIQUES = [
        'ouverture_envoi'      => '~<u>\s*Ouverture[^<]*</u>~i',
        'pu'                   => '~<strong>\s*Refrain\s+de\s+PU\s*:?\s*</strong>~i',
        'offertoire_communion' => '~<u>\s*Offertoire[^<]*</u>~i',
    ];

    /**
     * Agenda : date (AAAA-MM-JJ) => URL de la fiche du dimanche.
     *
     * @return array<string,string>
     */
    public static function parseAgenda(string $html): array
    {
        $dates = [];
        if (preg_match_all(
            '~(\d{2})/(\d{2})/(\d{4})\s*\|\s*<a\s+href="([^"]+)"~i',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $url = html_entity_decode(trim($m[4]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $iso = sprintf('%s-%s-%s', $m[3], $m[2], $m[1]);
                // La première occurrence gagne (l'agenda peut lister deux messes
                // le même jour — on prend celle du dimanche, citée en premier).
                $dates[$iso] ??= $url;
            }
        }

        return $dates;
    }

    /**
     * Fiche d'un dimanche : rubrique => liste de chants proposés.
     *
     * @return array<string,list<array{titre:string,url:string,ref:string}>>
     */
    public static function parseEvenement(string $html): array
    {
        // Bornes de chaque rubrique : de son titre jusqu'au titre suivant.
        $reperes = [];
        foreach (self::RUBRIQUES as $cle => $motif) {
            if (preg_match($motif, $html, $m, PREG_OFFSET_CAPTURE)) {
                $reperes[] = ['cle' => $cle, 'debut' => $m[0][1] + strlen($m[0][0])];
            }
        }
        usort($reperes, static fn ($a, $b) => $a['debut'] <=> $b['debut']);

        $out = [];
        foreach ($reperes as $i => $repere) {
            // La liste d'une rubrique tient dans un seul paragraphe : on s'arrête
            // au premier </p> (ou au titre de rubrique suivant) pour ne pas
            // ramasser les tableaux d'enregistrements qui suivent.
            $finParagraphe = strpos($html, '</p>', $repere['debut']);
            $fin = min(
                $reperes[$i + 1]['debut'] ?? strlen($html),
                $finParagraphe === false ? strlen($html) : $finParagraphe
            );
            $out[$repere['cle']] = self::chantsDansBloc(substr($html, $repere['debut'], $fin - $repere['debut']));
        }

        return $out;
    }

    /**
     * Rubrique correspondant à un type de section de feuille, ou null si le type
     * n'a pas de proposition sur le site (ex. sections personnalisées).
     */
    public static function rubriquePourType(string $type): ?string
    {
        return match ($type) {
            'entree', 'envoi'         => 'ouverture_envoi',
            'offertoire', 'communion' => 'offertoire_communion',
            'priere_universelle'      => 'pu',
            default                   => null,
        };
    }

    /**
     * Liens de chant d'un fragment de rubrique : uniquement les fiches du
     * répertoire du site (pas les ordinaires de messe ni les psaumes),
     * dédoublonnés par ref.
     *
     * @return list<array{titre:string,url:string,ref:string}>
     */
    private static function chantsDansBloc(string $bloc): array
    {
        $chants = [];
        if (!preg_match_all('~<a\s[^>]*href="([^"]+)"[^>]*>(.*?)</a>~is', $bloc, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $m) {
            $url = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $chemin = (string) parse_url($url, PHP_URL_PATH);
            if (preg_match('~/(?:messes|psaumes)/~i', $chemin)) {
                continue;
            }
            $ref = ChoralePoleFontainebleau::ref($url);
            if ($ref === '' || isset($chants[$ref])) {
                continue;
            }
            // Le texte du lien avale parfois la ponctuation qui le suit (« … ; »).
            $titre = rtrim(Paroles::ligne($m[2]), " ;,");
            if ($titre === '') {
                continue;
            }
            $chants[$ref] = [
                'titre' => $titre,
                'url'   => $url,
                'ref'   => $ref,
            ];
        }

        return array_values($chants);
    }
}
