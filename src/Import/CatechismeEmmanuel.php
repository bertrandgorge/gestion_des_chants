<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Analyse des pages du site « Catéchisme Emmanuel » (https://catechisme-emmanuel.com).
 *
 * Uniquement des fonctions pures (HTML → données) : la récupération réseau et
 * l'écriture en base sont gérées par bin/import_catechisme_emmanuel.php.
 *
 *  - la liste complète : /tous-les-chants/ (titre, thème, code IEV, lien de la fiche) ;
 *  - la fiche d'un chant : /chants/{slug}/ (titre, code IEV, paroles).
 *
 * La particularité de ce répertoire est le code « IEV » (ex. « IEV 19-06 »), absent
 * de chantonseneglise.fr : il est conservé pour rapprocher les deux imports
 * (voir App\Import\Repertoire).
 */
final class CatechismeEmmanuel
{
    public const BASE_URL = 'https://catechisme-emmanuel.com';

    public static function urlListe(): string
    {
        return self::BASE_URL . '/tous-les-chants/';
    }

    public static function urlChant(string $slug): string
    {
        return self::BASE_URL . '/chants/' . $slug . '/';
    }

    /**
     * Extrait la liste des chants de la page /tous-les-chants/.
     *
     * @return array<int,array{slug:string,url:string,titre:string,theme:string,code_repertoire:?string}>
     *         indexé par slug (dédoublonné).
     */
    public static function parseListe(string $html): array
    {
        $section = self::premierGroupe('~class="[^"]*liste-chants[^"]*"(.*?)</section>~s', $html) ?: $html;

        $chants = [];
        $theme  = '';
        if (preg_match_all(
            '~<h5>(?<theme>[^<]+)'
            . '|<li><a\s+href="(?<url>' . preg_quote(self::BASE_URL, '~') . '/chants/[^"]+)">'
            . '<strong>(?<titre>.*?)</strong>\s*<span>(?<code>.*?)</span>~s',
            $section,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                if (($m['theme'] ?? '') !== '') {
                    $theme = trim($m['theme']);
                    continue;
                }
                $slug = self::slug($m['url']);
                if ($slug === '' || isset($chants[$slug])) {
                    continue;
                }
                $chants[$slug] = [
                    'slug'            => $slug,
                    'url'             => self::urlChant($slug),
                    'titre'           => Paroles::ligne($m['titre']),
                    'theme'           => $theme,
                    'code_repertoire' => Repertoire::iev($m['code'], false),
                ];
            }
        }

        return $chants;
    }

    /**
     * Extrait les données d'une fiche /chants/{slug}/.
     *
     * @param string  $codeListe  code IEV vu dans la liste (repli si la fiche ne le porte pas).
     * @return array{titre:string,code_repertoire:?string,theme:string,type:string,nom:string,auteur:string,chant:string}|null
     *         null si la fiche ne contient pas de paroles exploitables.
     */
    public static function parseChant(string $html, string $codeListe = ''): ?array
    {
        $titre = Paroles::ligne(self::premierGroupe(
            '~<h1 class="white">\s*<span>[^<]*</span>(.*?)</h1>~s',
            $html
        ));
        $theme = Paroles::ligne(self::premierGroupe(
            '~class="white bgwhite-1"[^>]*>(.*?)</a>~s',
            $html
        ));
        $codeFiche = self::premierGroupe('~<h2>.*?<span[^>]*>\s*(.*?)\s*</span>\s*</h2>~s', $html);
        $codeRepertoire = Repertoire::iev($codeFiche, false) ?? Repertoire::iev($codeListe, false);

        // Paroles : tout ce qui suit « chant-contenu », jusqu'au bloc de partage
        // (les vers sont tantôt des <p>, tantôt des <div class="ujudUb">).
        $bloc = self::premierGroupe(
            '~<div class="chant-contenu">(.*?)<(?:div[^>]*\bheateor|script\b|/section)~s',
            $html
        );
        if ($bloc === '') {
            $bloc = self::premierGroupe('~<div class="chant-contenu">(.*)~s', $html);
        }
        $chant = Paroles::format(Paroles::multiligne($bloc));

        if ($titre === '' || mb_strlen($chant) < 15) {
            return null;
        }

        $type = TypeLiturgique::deduire($titre);

        return [
            'titre'           => $titre,
            'code_repertoire' => $codeRepertoire,
            'theme'           => $theme,
            'type'            => $type,
            'nom'             => TypeLiturgique::nom($theme, $type),
            'auteur'          => self::auteur($html),
            'chant'           => $chant,
        ];
    }

    /**
     * Auteur(s) tiré(s) de la mention de crédits sous les paroles, p. ex.
     * « Paroles et musique : Communauté de l'Emmanuel (E. Baranger) » ou
     * « Paroles : J.-L. Fradon - Musique : B. Ben ». Chaîne vide si absente.
     */
    public static function auteur(string $html): string
    {
        $credits = self::premierGroupe('~font-size:0\.7em[^"]*">\s*<em>(.*?)</em>~s', $html);
        if ($credits === '') {
            $credits = self::premierGroupe('~font-size:0\.7em[^"]*">\s*([^<]*?)\s*(?:<br|</p>)~i', $html);
        }
        $credits = Paroles::ligne($credits);
        if ($credits === '') {
            return '';
        }

        // Retire les intitulés de rôle (« Paroles et musique : », « Musique : »…).
        $credits = (string) preg_replace(
            '~\b(?:paroles et musique|paroles|musique|harmonisation|arrangements?|orchestration|adaptation|texte)\s*:\s*~iu',
            '',
            $credits
        );

        // Sépare les intervenants (séparés par «  - » / «  – » sur ce site).
        $noms = [];
        foreach (preg_split('~\s+[-–—]\s+~u', $credits) ?: [] as $nom) {
            $nom = trim($nom, " \t;");
            if ($nom !== '' && !in_array($nom, $noms, true)) {
                $noms[] = $nom;
            }
        }

        return implode(' / ', $noms);
    }

    // --- Internes ---------------------------------------------------------

    private static function premierGroupe(string $regex, string $sujet): string
    {
        return preg_match($regex, $sujet, $m) ? $m[1] : '';
    }

    private static function slug(string $url): string
    {
        return preg_match('~/chants/([a-z0-9-]+)/?$~i', $url, $m) ? $m[1] : '';
    }
}
