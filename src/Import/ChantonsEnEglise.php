<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Analyse des pages du site « Chantons en Église » (https://www.chantonseneglise.fr).
 *
 * Uniquement des fonctions pures (HTML → données) : la récupération réseau et
 * l'écriture en base sont gérées par bin/import_chantonseneglise.php.
 *
 *  - le catalogue par lettre : /catalogue/chant/A ... /catalogue/chant/Z
 *    (limité à 1000 résultats ; on redécoupe alors par préfixe : /catalogue/chant/AB)
 *  - la fiche paroles : /voir-texte/{id} (titre, cote Secli, auteurs, type, texte)
 *
 * Le nettoyage / la mise en forme des paroles et la déduction du type liturgique
 * sont communs à tous les imports : voir App\Import\Paroles et App\Import\TypeLiturgique.
 */
final class ChantonsEnEglise
{
    public const BASE_URL = 'https://www.chantonseneglise.fr';

    /** Le catalogue par préfixe est tronqué à ce nombre de résultats. */
    public const LIMITE_CATALOGUE = 1000;

    /** Type retenu quand la catégorie du site ne permet aucune identification. */
    public const TYPE_DEFAUT = TypeLiturgique::DEFAUT;

    /** Caractères testés pour redécouper un préfixe de catalogue tronqué. */
    public const CARACTERES_PREFIXE = [
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M',
        'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z',
        '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', "'", ' ', '-',
    ];

    /**
     * Extrait la liste des chants d'une page de catalogue.
     *
     * @return array<int,array{id:int,slug:string,titre:string}> indexé par id
     */
    public static function parseCatalogue(string $html): array
    {
        $chants = [];
        if (preg_match_all(
            '~<a class="nocolor" href="/chant/(\d+)/([^"]+)">\s*(.*?)\s*(?:\n|<)~s',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $id = (int) $m[1];
                if ($id === 0 || isset($chants[$id])) {
                    continue;
                }
                $chants[$id] = [
                    'id'    => $id,
                    'slug'  => $m[2],
                    'titre' => Paroles::ligne($m[3]),
                ];
            }
        }

        return $chants;
    }

    /** La page de catalogue est-elle tronquée (trop de résultats) ? */
    public static function catalogueTronque(string $html): bool
    {
        return count(self::parseCatalogue($html)) >= self::LIMITE_CATALOGUE;
    }

    /**
     * Extrait les données d'une fiche /voir-texte/{id}.
     *
     * Les paroles renvoyées sont brutes (Paroles::multiligne, pas Paroles::format) :
     * la mise en forme (couplets/refrain) se fait après coup, pas à la récupération
     * (voir bin/import_repertoire.php), pour ne jamais avoir à refaire une requête
     * réseau si la logique de mise en forme évolue.
     *
     * @return array{titre:string,code:string,auteur:string,editeur:string,code_repertoire:?string,categorie:string,type:string,chant:string}|null
     *         null si le chant n'a pas de paroles exploitables (raisons contractuelles…).
     */
    public static function parseVoirTexte(string $html): ?array
    {
        $h1        = self::premierGroupe('~<h1>(.*?)</h1>~s', $html);
        $auteur    = self::champLibelle($html, 'Auteur');
        $compo     = self::champLibelle($html, 'Compositeur');
        $editeur   = self::champLibelle($html, 'Editeur');
        $code      = self::champLibelle($html, 'Cote Secli');
        $categorieBrute = Paroles::ligne(self::premierGroupe('~<div class="mt-3">(.*?)</div>~s', $html));

        $paroles = self::premierGroupe('~<p class="py-4">(.*?)</p>~s', $html);
        $chant   = Paroles::multiligne($paroles);

        if ($chant === ''
            || mb_strlen($chant) < 15
            || stripos($chant, 'raisons contractuelles') !== false
            || stripos($chant, 'ne sont pas disponibles') !== false
        ) {
            return null;
        }

        $code      = self::normaliseCode($code);
        $titre     = self::titreSansCode(Paroles::ligne($h1), $code);
        $categorie = Repertoire::sansRefIev($categorieBrute);

        return [
            'titre'          => $titre,
            'code'           => $code,
            'auteur'         => Paroles::fusionneAuteurs($auteur, $compo),
            'editeur'        => $editeur,
            // Code de répertoire IEV (Emmanuel) : sert au rapprochement avec l'import
            // catechisme-emmanuel.com. On ne le retient que si l'éditeur est l'Emmanuel.
            'code_repertoire' => Repertoire::estEmmanuel($editeur) ? Repertoire::iev($categorieBrute) : null,
            'categorie'      => $categorie,
            // La catégorie du site est peu fiable : la cote SECLI prime (issue #11).
            'type'           => TypeLiturgique::deduire($categorie, $code),
            'chant'          => $chant,
        ];
    }

    /** Met en forme les paroles au format de l'application (délègue à Paroles). */
    public static function formatParoles(string $texte): string
    {
        return Paroles::format($texte);
    }

    /** Slug de section (App\SectionTypes::DEFAUT) déduit de la catégorie du site. */
    public static function typeInterne(string $categorie): string
    {
        return TypeLiturgique::deduire($categorie);
    }

    /** Libellé affiché pour une section de catalogue (colonne chants.nom). */
    public static function nomSection(string $categorie, string $type): string
    {
        return TypeLiturgique::nom($categorie, $type);
    }

    public static function urlChant(int $id, string $slug): string
    {
        return self::BASE_URL . '/chant/' . $id . '/' . $slug;
    }

    public static function urlVoirTexte(int $id): string
    {
        return self::BASE_URL . '/voir-texte/' . $id;
    }

    public static function urlCatalogue(string $prefixe): string
    {
        return self::BASE_URL . '/catalogue/chant/' . rawurlencode($prefixe);
    }

    // --- Internes ---------------------------------------------------------

    private static function premierGroupe(string $regex, string $sujet): string
    {
        return preg_match($regex, $sujet, $m) ? $m[1] : '';
    }

    /** Récupère « <div>Libellé : valeur</div> ». */
    private static function champLibelle(string $html, string $libelle): string
    {
        $regex = '~<div>\s*' . preg_quote($libelle, '~') . '\s*:\s*(.*?)</div>~s';

        return Paroles::ligne(self::premierGroupe($regex, $html));
    }

    private static function normaliseCode(string $code): string
    {
        $code = trim((string) preg_replace('~\s*/\s*~', ' / ', $code));

        return trim((string) preg_replace('~\s+~', ' ', $code));
    }

    /** « Devenez ce que vous recevez - D68-39 » → « Devenez ce que vous recevez ». */
    private static function titreSansCode(string $titre, string $code): string
    {
        $premierCode = trim(explode('/', $code)[0]);
        if ($premierCode !== '') {
            $titre = (string) preg_replace(
                '~\s*[-–—]\s*' . preg_quote($premierCode, '~') . '\s*$~u',
                '',
                $titre
            );
        }

        return trim($titre);
    }
}
