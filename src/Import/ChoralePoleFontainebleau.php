<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Analyse des pages du site « Chorale Paroissiale du Pôle Missionnaire de
 * Fontainebleau » (https://choralepolefontainebleau.org).
 *
 * Uniquement des fonctions pures (HTML → données) : la récupération réseau et
 * l'écriture en base sont gérées par bin/import_chorale_pole_fontainebleau.php.
 *
 *  - la liste complète : /category/bibliotheque/chants/ contient un tableau
 *    « index-chants » (titre, thématique, lien de la fiche) listant TOUS les
 *    chants, quelle que soit la pagination WordPress ;
 *  - la fiche d'un chant : /bibliotheque/[.../]{slug}-{id}/ (titre, cote Secli,
 *    auteurs, éditeur, paroles).
 *
 * Le nettoyage / la mise en forme des paroles et la déduction du type liturgique
 * sont communs à tous les imports : voir App\Import\Paroles et App\Import\TypeLiturgique.
 */
final class ChoralePoleFontainebleau
{
    public const BASE_URL = 'https://choralepolefontainebleau.org';

    public static function urlListe(): string
    {
        return self::BASE_URL . '/category/bibliotheque/chants/';
    }

    /**
     * Extrait la liste des chants du tableau « index-chants ».
     *
     * @return array<string,array{ref:string,url:string,titre:string,theme:string}>
     *         indexé par ref (l'identifiant numérique de la fiche), dédoublonné.
     */
    public static function parseListe(string $html): array
    {
        $tbody = self::premierGroupe(
            '~<table[^>]*\bid="index-chants".*?<tbody>(.*?)</tbody>~s',
            $html
        );
        if ($tbody === '') {
            return [];
        }

        $chants = [];
        if (preg_match_all(
            '~<tr>\s*<td>\s*<a\s+href="([^"]+)"[^>]*>(.*?)</a>\s*</td>\s*<td>(.*?)</td>~s',
            $tbody,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $url = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $ref = self::ref($url);
                if ($ref === '' || isset($chants[$ref])) {
                    continue;
                }
                $chants[$ref] = [
                    'ref'   => $ref,
                    'url'   => $url,
                    'titre' => Paroles::ligne($m[2]),
                    'theme' => Paroles::ligne($m[3]),
                ];
            }
        }

        return $chants;
    }

    /** Identifiant numérique en fin d'URL (« .../salve-regina-1vp-73656/ » → « 73656 »). */
    public static function ref(string $url): string
    {
        return preg_match('~-(\d+)/?(?:[?#].*)?$~', $url, $m) ? $m[1] : '';
    }

    /**
     * Extrait les données d'une fiche chant.
     *
     * Les paroles renvoyées sont brutes (Paroles::multiligne, pas Paroles::format) :
     * la mise en forme (couplets/refrain) se fait après coup, pas à la récupération
     * (voir bin/import_repertoire.php), pour ne jamais avoir à refaire une requête
     * réseau si la logique de mise en forme évolue.
     *
     * @param string $themeListe  thématique vue dans la liste (le seul endroit fiable).
     * @return array{titre:string,code:string,code_repertoire:?string,auteur:string,editeur:string,theme:string,type:string,nom:string,chant:string}|null
     *         null si la fiche ne contient pas de paroles exploitables.
     */
    public static function parseChant(string $html, string $themeListe = ''): ?array
    {
        $article = self::premierGroupe('~(<article id="post-\d+".*</article>)~s', $html) ?: $html;

        $titre = Paroles::ligne(self::premierGroupe('~<header>\s*<h1>(.*?)</h1>~s', $article));

        // « Références de la partition » : bloc en texte libre, une info par ligne
        // (Cote SECLI, « P & M : … », « T : … », « M : … », « Éditeur : … »…).
        $refs = Paroles::multiligne(self::premierGroupe(
            '~Références de la partition\s*:?\s*</h4>(.*?)</span>~s',
            $article
        ));

        $code    = self::coteSecli($refs);
        $auteur  = self::auteurs($refs);
        $editeur = self::editeur($refs);

        $chant = self::paroles($article);

        if ($titre === '' || mb_strlen($chant) < 15) {
            return null;
        }

        // La thématique du site est multi-valuée et en capitales
        // (« OUVERTURE – ENVOI, LOUANGES ») : on déduit le type liturgique du
        // premier libellé, ramené en minuscules pour un nom de section lisible.
        $theme        = $themeListe;
        $themePremier = mb_strtolower(trim(explode(',', $theme)[0]), 'UTF-8');
        $type         = TypeLiturgique::deduire($themePremier !== '' ? $themePremier : $titre);

        return [
            'titre'           => $titre,
            'code'            => $code,
            // Code de répertoire IEV (Emmanuel) : seulement si l'éditeur/l'auteur
            // relève de l'Emmanuel et qu'un code figure dans les références.
            'code_repertoire' => Repertoire::estEmmanuel($editeur . ' ' . $auteur)
                ? Repertoire::iev($refs)
                : null,
            'auteur'          => $auteur,
            'editeur'         => $editeur,
            'theme'           => $theme,
            'type'            => $type,
            'nom'             => TypeLiturgique::nom($themePremier, $type),
            'chant'           => $chant,
        ];
    }

    /** Met en forme les paroles au format de l'application (délègue à Paroles). */
    public static function formatParoles(string $texte): string
    {
        return Paroles::format($texte);
    }

    /** Slug de section (App\SectionTypes::DEFAUT) déduit d'une thématique du site. */
    public static function typeInterne(string $theme): string
    {
        $premier = mb_strtolower(trim(explode(',', $theme)[0]), 'UTF-8');

        return TypeLiturgique::deduire($premier !== '' ? $premier : $theme);
    }

    /** Libellé affiché pour une section (colonne chants.nom). */
    public static function nomSection(string $theme, string $type): string
    {
        return TypeLiturgique::nom(mb_strtolower(trim(explode(',', $theme)[0]), 'UTF-8'), $type);
    }

    // --- Internes ---------------------------------------------------------

    private static function premierGroupe(string $regex, string $sujet): string
    {
        return preg_match($regex, $sujet, $m) ? $m[1] : '';
    }

    /**
     * Paroles brutes (HTML → texte). Gère les trois gabarits rencontrés :
     *   <div><h3>Paroles :</h3> … </div>
     *   <h3>Paroles:</h3><div class="paroles"> … </div>
     *   <div><h3>Paroles :</h3><div class="modal-body"><div class="chantons"> … </div></div></div>
     * Le bloc s'arrête à la section suivante (« Documentation »).
     */
    private static function paroles(string $article): string
    {
        if (!preg_match('~<h3>\s*Paroles\s*:?\s*</h3>(.*)$~s', $article, $m)) {
            return '';
        }
        $bloc = (string) preg_split('~<h3>|<div id="document~', $m[1])[0];
        $texte = Paroles::multiligne($bloc);

        // Fiches à contenu partiel : on retire la mention « identifiez-vous pour
        // la suite », on garde ce qui est affiché.
        return (string) preg_replace(
            '~^.*(?:fins pédagogiques|Veuillez vous identifier|Ce contenu est diffusé).*$~mu',
            '',
            $texte
        );
    }

    /** « Cote SECLI: Y 29-45 » / « Ancienne Cote SECLI : L29-13 » → « Y 29-45 ». */
    private static function coteSecli(string $texte): string
    {
        if (preg_match(
            '~cote\s+SECLI\s*:?\s*([A-Za-z]{1,3})\s*[-\s]\s*(\d{1,3})\s*-\s*(\d{1,3})~i',
            $texte,
            $m
        )) {
            return strtoupper($m[1]) . ' ' . $m[2] . '-' . $m[3];
        }
        if (preg_match('~cote\s+SECLI\s*:?\s*([A-Za-z]{1,3})\s*(\d{1,3})-(\d{1,3})~i', $texte, $m)) {
            return strtoupper($m[1]) . ' ' . $m[2] . '-' . $m[3];
        }

        return '';
    }

    /**
     * Auteur(s) / compositeur(s) tirés des lignes « P & M : … », « T & M … »,
     * « T : … », « M : … », « Auteurs : … », « Compositeurs : … ». « Domaine
     * public » est ignoré. Renvoie les noms joints par «  / ».
     */
    private static function auteurs(string $texte): string
    {
        $noms = [];
        foreach (explode("\n", $texte) as $ligne) {
            if (!preg_match(
                '~^\s*(?:T\s*&\s*M|P\s*&\s*M|Paroles?\s*(?:et|&)\s*musique'
                . '|Auteurs?|Compositeurs?|Paroles?|Musique|Texte|T|M|P)\s*[:.\-]?\s+(\S.*)$~iu',
                $ligne,
                $m
            )) {
                continue;
            }
            $valeur = trim($m[1], " \t.-–—");
            if ($valeur === '' || preg_match('~domaine\s+public~i', $valeur)) {
                continue;
            }
            foreach (preg_split('~\s*[/,]\s*|\s+et\s+|\s+[–—-]\s+~u', $valeur) ?: [] as $nom) {
                $nom = trim((string) preg_replace('~\(\s+~', '(', $nom));
                if ($nom !== '' && !in_array($nom, $noms, true)) {
                    $noms[] = $nom;
                }
            }
        }

        return implode(' / ', $noms);
    }

    /** Éditeur, tiré d'une ligne « Ed : … » / « Éditeur : … » / « Éditions … ». */
    private static function editeur(string $texte): string
    {
        foreach (explode("\n", $texte) as $ligne) {
            if (preg_match(
                '~(?<![A-Za-zÀ-ÿ])(?:Éditeur|Editeur|Éd|Ed)(?:\s*[:.]\s*|\s+)(\S.*)$~iu',
                $ligne,
                $m
            )) {
                return trim($m[1], " \t.-–—");
            }
            if (preg_match('~^\s*(Éditions?\b.*)$~iu', $ligne, $m)) {
                return trim($m[1], " \t.-–—");
            }
        }

        return '';
    }
}
