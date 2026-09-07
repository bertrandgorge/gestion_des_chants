<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Analyse des pages « ordinaire de messe » du site de la Communauté de l'Emmanuel
 * (https://emmanuel.info), à ne pas confondre avec catechisme-emmanuel.com (voir
 * App\Import\CatechismeEmmanuel).
 *
 * Chaque ordinaire de messe (« Messe de Saint Jean »…) a une URL unique et
 * regroupe plusieurs chants (Kyrie, Gloria, Alléluia, Sanctus, Anamnèse, Agnus…)
 * qui partagent la même musique : c'est cette page, pas un chant, qui est l'unité
 * de récupération (voir bin/import_emmanuel_ordinaires.php, qui écrit une fiche
 * du répertoire par chant reconnu, toutes marquées du même `ordinaire`).
 *
 *  - la liste des ordinaires : /ordinaires-de-messe/ (chaque page d'ordinaire
 *    renvoie elle-même vers les autres, mais cette page dédiée est plus stable
 *    comme point d'entrée) ;
 *  - une fiche d'ordinaire : /ordinaire-de-messe/{slug}/ (nom, crédits, paroles
 *    de chaque partie reconnue).
 */
final class EmmanuelOrdinaires
{
    public const BASE_URL = 'https://emmanuel.info';

    /** Parties reconnues (slug App\SectionTypes) selon le libellé lu sur le site. Ordre : le premier motif gagne. */
    private const PARTIES = [
        'kyrie'    => 'kyrie',
        'gloria'   => 'gloria',
        'alleluia' => 'alleluia',
        'sanctus'  => 'sanctus',
        'anamnese' => 'anamnese',
        'agnus'    => 'agnus',
    ];

    public static function urlListe(): string
    {
        return self::BASE_URL . '/ordinaires-de-messe/';
    }

    public static function urlOrdinaire(string $slug): string
    {
        return self::BASE_URL . '/ordinaire-de-messe/' . $slug . '/';
    }

    /**
     * Extrait, depuis n'importe quelle page listant des liens d'ordinaires
     * (la page /ordinaires-de-messe/, ou à défaut une fiche d'ordinaire qui
     * renvoie vers les autres), les couples slug => URL, dédoublonnés.
     *
     * @return array<string,string>
     */
    public static function parseListe(string $html): array
    {
        if (!preg_match_all(
            '~href="(' . preg_quote(self::BASE_URL, '~') . '/ordinaire-de-messe/([a-z0-9-]+)/)"~',
            $html,
            $m,
            PREG_SET_ORDER
        )) {
            return [];
        }

        $ordinaires = [];
        foreach ($m as $r) {
            $ordinaires[$r[2]] = $r[1];
        }

        return $ordinaires;
    }

    /**
     * Extrait le nom de l'ordinaire, les crédits communs et les paroles de
     * chaque partie reconnue d'une fiche /ordinaire-de-messe/{slug}/.
     *
     * Les paroles renvoyées par partie sont brutes (Paroles::multiligne, pas
     * Paroles::format) : la mise en forme se fait à l'import, pas ici (voir
     * App\Import\CatechismeEmmanuel pour la même convention).
     *
     * @return array{nom:string,auteur:string,chants:list<array{type:string,nom:string,chant:string}>}|null
     *         null si le nom de l'ordinaire ou aucune partie exploitable n'a été trouvée.
     */
    public static function parseOrdinaire(string $html): ?array
    {
        $nom = self::nomOrdinaire($html);
        if ($nom === '') {
            return null;
        }

        $bloc = self::premierGroupe(
            '~PAROLES DE (?:LA )?MESSE.*?</h2>(.*?)Retrouvez d.autres ordinaires~s',
            $html
        );
        if ($bloc === '') {
            return null;
        }

        // Le titre d'une partie est presque toujours <p><strong>X</strong></p>,
        // mais certaines fiches (arrangements alternatifs) l'enveloppent aussi
        // d'un <em>, dans un ordre ou l'autre (<p><em><strong>X</strong></em></p>,
        // <p><strong><em>X</em></strong></p>…) — jamais <em> seul, qui désigne
        // au contraire un paragraphe de crédits (voir credits() ci-dessous).
        if (!preg_match_all(
            '~<p>\s*(?:<em>\s*)?<strong>\s*(?:<em>\s*)?([^<]+?)\s*(?:</em>\s*)?</strong>\s*(?:</em>\s*)?</p>~',
            $bloc,
            $titres,
            PREG_OFFSET_CAPTURE
        )) {
            return null;
        }

        $auteur = self::credits($bloc, (int) $titres[0][0][1]);

        $chants = [];
        $n = count($titres[0]);
        for ($i = 0; $i < $n; $i++) {
            $libelle = Paroles::ligne((string) $titres[1][$i][0]);
            $type = self::typePartie($libelle);
            if ($type === null) {
                continue; // partie non reconnue (« Doxologie »…) : aucune section de feuille n'y correspond
            }

            $debut = (int) $titres[0][$i][1] + strlen((string) $titres[0][$i][0]);
            $fin   = $i + 1 < $n ? (int) $titres[0][$i + 1][1] : strlen($bloc);
            // La dernière partie est suivie, dans le même bloc, des mentions de
            // copyright (« Titre original… », « © … ») : jamais les paroles
            // elles-mêmes — on les retire après mise en forme (leur emballage
            // HTML n'est pas homogène d'une fiche à l'autre).
            $texte = self::sansMentionsFinales(Paroles::multiligne(substr($bloc, $debut, $fin - $debut)));
            if (mb_strlen($texte) < 5) {
                continue;
            }

            $chants[] = ['type' => $type, 'nom' => $libelle, 'chant' => $texte];
        }

        if ($chants === []) {
            return null;
        }

        return ['nom' => $nom, 'auteur' => $auteur, 'chants' => $chants];
    }

    /**
     * Nom de l'ordinaire : le titre affiché sous « ORDINAIRE DE MESSE » (fiable,
     * propre à chaque page) plutôt que la balise <title>, régulièrement fausse
     * sur ce site (ex. plusieurs fiches distinctes portent le <title> « … Saint
     * Augustin »). Remis en forme (le site le rend tantôt tout en capitales,
     * tantôt dans une casse fantaisiste) ; repli sur <title> si la page ne suit
     * pas cette mise en page.
     */
    private static function nomOrdinaire(string $html): string
    {
        $brut = self::premierGroupe('~ORDINAIRE DE MESSE\s*</h2>.*?<h2[^>]*>\s*(.*?)\s*</h2>~s', $html);
        if ($brut === '') {
            $brut = self::premierGroupe('~<title>\s*Ordinaire de messe\s*:\s*(.*?)</title>~si', $html);
        }

        $nom = Paroles::ligne($brut);

        return $nom !== '' ? mb_convert_case($nom, MB_CASE_TITLE, 'UTF-8') : '';
    }

    /**
     * Retire les dernières lignes d'un texte de partie qui sont des mentions de
     * copyright / traduction (« © 1998… », « Titre original (DE)… ») plutôt que
     * des paroles : toujours en fin de bloc, jamais mêlées aux paroles.
     */
    private static function sansMentionsFinales(string $texte): string
    {
        $lignes = explode("\n", $texte);
        while ($lignes !== []) {
            $derniere = trim((string) end($lignes));
            if ($derniere !== '' && !self::estMentionFinale($derniere)) {
                break;
            }
            array_pop($lignes);
        }

        return rtrim(implode("\n", $lignes));
    }

    private static function estMentionFinale(string $ligne): bool
    {
        return str_contains($ligne, '©')
            || (bool) preg_match('~^(?:titre original\b|traduction\s*:|\(c\)\s*\d{4})~iu', $ligne);
    }

    /**
     * Libellé de partie (« Kyrie », « Agnus Dei »…) → slug App\SectionTypes, ou
     * null si non reconnu (ex. « Doxologie », sans section de feuille dédiée).
     */
    private static function typePartie(string $libelle): ?string
    {
        $n = Paroles::sansAccents(mb_strtolower(trim($libelle), 'UTF-8'));
        foreach (self::PARTIES as $motif => $type) {
            if (str_starts_with($n, $motif)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Crédits communs à l'ordinaire (« Paroles : … » / « Musique : … »), lus
     * dans les paragraphes qui précèdent la première partie reconnue.
     */
    private static function credits(string $bloc, int $avantOffset): string
    {
        $entete = substr($bloc, 0, $avantOffset);
        if (!preg_match_all('~<p><em>\s*(.*?)\s*</em></p>~s', $entete, $m)) {
            return '';
        }

        $noms = [];
        foreach ($m[1] as $ligne) {
            $ligne = Paroles::ligne($ligne);
            $ligne = (string) preg_replace('~\b(?:paroles|musique)\s*:\s*~iu', '', $ligne);
            $ligne = trim((string) preg_replace('~\bN[°o]\s*[\d-]+\.?\s*$~iu', '', $ligne));
            if ($ligne !== '' && !in_array($ligne, $noms, true)) {
                $noms[] = $ligne;
            }
        }

        return implode(' / ', $noms);
    }

    private static function premierGroupe(string $regex, string $sujet): string
    {
        return preg_match($regex, $sujet, $m) ? $m[1] : '';
    }
}
