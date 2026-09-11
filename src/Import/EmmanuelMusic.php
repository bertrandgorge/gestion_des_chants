<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Analyse des pages du site « Emmanuel Music », la boutique numérique de
 * partitions et enregistrements de la Communauté de l'Emmanuel
 * (https://emmanuelmusic.net).
 *
 * Uniquement des fonctions pures (HTML → données) : la récupération réseau et
 * l'écriture en base sont gérées par bin/import_emmanuel_music.php.
 *
 *  - la liste : /rechercher/?_nat=chant (nature « chant »), paginée
 *    (/rechercher/page/{n}/?_nat=chant, voir self::nombreDePages()) ; chaque
 *    fiche listée porte un drapeau de langue (<span class="fi fi-XX">) dans son
 *    <h2> — seul .fi-fr (français) est retenu ;
 *  - la fiche d'un chant : /produit/{slug}/ (titre, crédits, paroles, cote
 *    IEV/SECLI, rubrique).
 *
 * Particularité de ce catalogue : un même chant y a souvent plusieurs fiches
 * produit (MP3, partition voix, partition chœur, orchestration…) partageant les
 * mêmes paroles, distinguées par un suffixe dans le titre (« [MP3] »,
 * « [PARTIEV] »…). La liste est donc dédoublonnée par titre de base (voir
 * self::dedupliquer()), en gardant la variante la plus susceptible de porter
 * des paroles exploitables : certaines fiches PARTIEV ne portent qu'un lien
 * « Apprendre ce chant », sans texte.
 *
 * Comme catechisme-emmanuel.com (voir App\Import\CatechismeEmmanuel), ce
 * répertoire porte un code « IEV » propre à l'Emmanuel (ici « COTE IEV » sur la
 * fiche), mémorisé dans import_journal.code_repertoire — et, à la différence de
 * catechisme-emmanuel.com, une véritable cote SECLI est parfois disponible
 * (« COTE SECLI »), plus fiable pour déduire le type liturgique (voir
 * App\Import\Secli).
 */
final class EmmanuelMusic
{
    public const BASE_URL = 'https://emmanuelmusic.net';

    /**
     * Priorité de variante produit (la plus fiable d'abord) pour le
     * dédoublonnage par titre de base : voir self::dedupliquer().
     */
    private const PRIORITE_VARIANTE = ['MP3' => 0, 'PARTIEV' => 1, 'PARTITION' => 2, 'ORCHIEV' => 3];

    public static function urlListe(int $page = 1): string
    {
        return $page <= 1
            ? self::BASE_URL . '/rechercher/?_nat=chant'
            : self::BASE_URL . '/rechercher/page/' . $page . '/?_nat=chant';
    }

    /**
     * Nombre total de pages de résultats, lu dans la configuration JSON (inline)
     * du plugin de recherche/pagination — présente sur chaque page de résultats,
     * quel que soit le numéro de page demandé. 1 si le motif n'est pas trouvé
     * (page isolée, ou évolution du site).
     */
    public static function nombreDePages(string $html): int
    {
        return preg_match('~&quot;maxPages&quot;:(\d+)~', $html, $m) ? max(1, (int) $m[1]) : 1;
    }

    /**
     * Extrait les fiches en français (drapeau .fi-fr) d'une page de résultats.
     * Ni dédoublonnée entre variantes produit, ni indexée : à combiner sur
     * plusieurs pages puis à passer à self::dedupliquer().
     *
     * @return list<array{ref:string,url:string,titre:string}>
     */
    public static function parseListe(string $html): array
    {
        if (!preg_match_all(
            '~<h2>\s*<span class="fi ([a-z-]+)"></span>\s*<a href="([^"]+)">(.*?)</a>~s',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            return [];
        }

        $chants = [];
        foreach ($matches as $m) {
            if ($m[1] !== 'fi-fr') {
                continue;
            }
            $url   = html_entity_decode(trim($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $ref   = self::ref($url);
            $titre = Paroles::ligne($m[3]);
            if ($ref === '' || $titre === '') {
                continue;
            }
            $chants[] = ['ref' => $ref, 'url' => $url, 'titre' => $titre];
        }

        return $chants;
    }

    /**
     * Dédoublonne une liste de fiches (fusion de plusieurs pages de résultats)
     * par titre de base — le suffixe de variante produit retiré (« [MP3] »,
     * « [PARTIEV] »…) : voir self::titreBase(). En cas de plusieurs variantes
     * pour un même titre, garde la plus susceptible de porter des paroles
     * exploitables (self::PRIORITE_VARIANTE) ; à égalité, la première rencontrée.
     *
     * @param list<array{ref:string,url:string,titre:string}> $fiches
     * @return array<string,array{ref:string,url:string,titre:string}> indexé par ref
     */
    public static function dedupliquer(array $fiches): array
    {
        $retenues = [];
        foreach ($fiches as $fiche) {
            $base     = self::titreBase($fiche['titre']);
            $priorite = self::PRIORITE_VARIANTE[self::variante($fiche['titre']) ?? ''] ?? 99;
            if (isset($retenues[$base]) && $retenues[$base]['priorite'] <= $priorite) {
                continue;
            }
            $retenues[$base] = $fiche + ['priorite' => $priorite];
        }

        $chants = [];
        foreach ($retenues as $fiche) {
            unset($fiche['priorite']);
            $chants[$fiche['ref']] = $fiche;
        }

        return $chants;
    }

    /** Slug en fin d'URL (« .../produit/comme-un-soleil-mp3/ » → « comme-un-soleil-mp3 »). */
    public static function ref(string $url): string
    {
        return preg_match('~/produit/([a-z0-9-]+)/?(?:[?#].*)?$~i', $url, $m) ? $m[1] : '';
    }

    /** Titre du chant sans le suffixe de variante produit (« [MP3] », « [PARTIEV] »…). */
    public static function titreBase(string $titre): string
    {
        return trim((string) preg_replace('~\s*\[[A-Za-z0-9]+\]\s*$~', '', $titre));
    }

    /**
     * Extrait les données d'une fiche /produit/{slug}/.
     *
     * Les paroles renvoyées sont brutes (Paroles::multiligne, pas Paroles::format) :
     * la mise en forme (couplets/refrain) se fait après coup (voir
     * bin/import_repertoire.php), pour ne jamais avoir à refaire une requête
     * réseau si la logique de mise en forme évolue.
     *
     * @return array{titre:string,code:string,code_repertoire:?string,auteur:string,categorie:string,type:string,nom:string,chant:string}|null
     *         null si la fiche ne contient pas de paroles exploitables (ex. une
     *         fiche « Apprendre ce chant » sans texte, ou un produit qui n'est
     *         pas un chant — recharge de partitions, livret d'orchestrations…).
     */
    public static function parseProduit(string $html): ?array
    {
        $titre = self::titreBase(Paroles::ligne(self::premierGroupe(
            '~<h[1-6][^>]*class="product_title[^"]*"[^>]*>(.*?)</h[1-6]>~s',
            $html
        )));

        $chant = self::paroles($html);
        if ($titre === '' || mb_strlen($chant) < 15) {
            return null;
        }

        $refs      = self::premierGroupe('~<section id="refs-produit">(.*?)</section>~s', $html);
        $code      = self::coteSecli($refs);
        $categorie = self::rubrique($html);
        // La rubrique du site (« Louange », « Marie »…) ne recoupe presque
        // jamais les libellés liturgiques reconnus : le titre sert de repli,
        // comme App\Import\ChoralePoleFontainebleau::parseChant.
        $categoriePremiere = mb_strtolower(trim(explode(',', $categorie)[0]), 'UTF-8');
        $type = TypeLiturgique::deduire($categoriePremiere !== '' ? $categoriePremiere : $titre, $code);

        return [
            'titre'           => $titre,
            'code'            => $code,
            // Code de répertoire IEV : toujours présent sur ce site (boutique
            // propre à l'Emmanuel), pas seulement quand l'éditeur/l'auteur
            // relève de l'Emmanuel (à la différence de ChoralePoleFontainebleau,
            // site tiers) — même convention que App\Import\CatechismeEmmanuel.
            'code_repertoire' => self::coteIev($refs),
            'auteur'          => self::auteur($html),
            'categorie'       => $categorie,
            'type'            => $type,
            'nom'             => TypeLiturgique::nom($categoriePremiere, $type),
            'chant'           => $chant,
        ];
    }

    // --- Internes ---------------------------------------------------------

    /** Suffixe de variante produit d'un titre (« Comme un soleil [MP3] » → « MP3 »), ou null. */
    private static function variante(string $titre): ?string
    {
        return preg_match('~\[([A-Za-z0-9]+)\]\s*$~', $titre, $m) ? strtoupper($m[1]) : null;
    }

    /**
     * Paroles brutes (HTML → texte) du widget « woocommerce-product-content »,
     * seul bloc de contenu de la fiche (jamais de <div> imbriqué : juste des
     * <p>/<strong>/<br> — la première paire de </div> consécutifs qui suit le
     * marque donc bien la fin du contenu).
     */
    private static function paroles(string $html): string
    {
        if (!preg_match(
            '~elementor-widget-woocommerce-product-content[^>]*>\s*<div class="elementor-widget-container">(.*?)</div>\s*</div>~s',
            $html,
            $m
        )) {
            return '';
        }

        return Paroles::multiligne($m[1]);
    }

    /** « COTE IEV: 01-08-FR » (bloc #refs-produit) → « IEV 01-08 », ou null si absente. */
    private static function coteIev(string $refs): ?string
    {
        return preg_match('~COTE IEV\s*:\s*</strong>\s*<em>\s*([^<]+?)\s*</em>~i', $refs, $m)
            ? Repertoire::iev('IEV ' . $m[1])
            : null;
    }

    /** « COTE SECLI: H503 » (bloc #refs-produit) → « H503 », ou chaîne vide si absente. */
    private static function coteSecli(string $refs): string
    {
        return preg_match('~COTE SECLI\s*:\s*</strong>\s*<em>\s*([^<]+?)\s*</em>~i', $refs, $m)
            ? trim($m[1])
            : '';
    }

    /**
     * Rubrique(s) du produit (« Louange », « Marie »…), lues dans le bloc
     * d'infos qui affiche « rubrique : ». Plusieurs valeurs sont jointes par
     * une virgule ; chaîne vide si le bloc est absent (produits hors chant).
     */
    private static function rubrique(string $html): string
    {
        if (!preg_match(
            '~rubrique\s*:\s*</span>\s*<span class="elementor-post-info__terms-list">(.*?)</span>~s',
            $html,
            $m
        ) || !preg_match_all('~<a[^>]*>(.*?)</a>~s', $m[1], $a)) {
            return '';
        }

        return implode(', ', array_map(static fn (string $s): string => Paroles::ligne($s), $a[1]));
    }

    /**
     * Crédits (« Paroles et musique : X », « Paroles : X – Musique : Y –
     * Adaptation : Z »…), lus dans le premier bloc de titre de la fiche qui
     * mentionne « paroles » ou « musique » — les autres blocs de titre (nom du
     * site, mentions de copyright, « Apprendre ce chant »…) n'en contiennent
     * jamais. Chaîne vide si aucun crédit (fiches « Apprendre ce chant » sans
     * texte, déjà écartées par mb_strlen($chant) < 15 dans self::parseProduit()).
     */
    private static function auteur(string $html): string
    {
        if (!preg_match_all('~<div class="elementor-heading-title[^"]*">(.*?)</div>~s', $html, $blocs)) {
            return '';
        }

        $roles = 'paroles et musique|paroles|musique|harmonisation|arrangements?|orchestration|adaptation|texte';
        foreach ($blocs[1] as $bloc) {
            $ligne = Paroles::ligne($bloc);
            if (!preg_match('~\b(?:paroles|musique)\b~iu', $ligne)) {
                continue;
            }
            if (!preg_match_all('~(?:' . $roles . ')\s*:\s*(.*?)(?=(?:' . $roles . ')\s*:|$)~iu', $ligne, $m)) {
                return '';
            }

            $noms = [];
            foreach ($m[1] as $valeur) {
                foreach (preg_split('~\s*[/,]\s*|\s+et\s+|\s+[–—-]\s+~u', trim($valeur, " \t.-–—")) ?: [] as $nom) {
                    $nom = trim($nom);
                    if ($nom !== '' && !in_array($nom, $noms, true)) {
                        $noms[] = $nom;
                    }
                }
            }

            return implode(' / ', $noms);
        }

        return '';
    }

    private static function premierGroupe(string $regex, string $sujet): string
    {
        return preg_match($regex, $sujet, $m) ? $m[1] : '';
    }
}
