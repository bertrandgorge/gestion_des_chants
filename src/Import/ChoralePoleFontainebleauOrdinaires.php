<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Analyse de la catégorie « Messes » du site « Chorale Paroissiale du Pôle
 * Missionnaire de Fontainebleau » (https://choralepolefontainebleau.org),
 * en vue d'en extraire les parties d'ordinaire de messe (issue #12).
 *
 * Contrairement à emmanuel.info (voir App\Import\EmmanuelOrdinaires, une
 * fiche = plusieurs parties), chaque partie a ici sa PROPRE fiche, au même
 * gabarit que les chants « normaux » du site (voir App\Import\ChoralePoleFontainebleau,
 * réutilisé par bin/import_chorale_pole_fontainebleau_ordinaires.php pour
 * analyser une fiche) — et rien ne relie fiablement entre elles les fiches
 * d'un même ordinaire : le rapprochement se fait donc uniquement par le
 * titre (« Sanctus messe du partage » → partie « sanctus » de l'ordinaire
 * « Messe du partage »), jamais par les liens de la « Références de la
 * partition » (présents sur certaines fiches seulement, et incomplets).
 *
 * Seules les fiches dont le titre contient explicitement « messe » après le
 * nom de la partie sont retenues comme partie d'ordinaire (ex. « Kyrie XVI »,
 * « Gloria Milan », qui désignent une messe grégorienne sans le dire dans le
 * titre, restent de simples chants du répertoire — non traités ici).
 */
final class ChoralePoleFontainebleauOrdinaires
{
    /** Slugs App\SectionTypes reconnus, indexés par le motif de tête du titre (sans accent, en minuscules). */
    private const PARTIES = [
        'kyrie'    => 'kyrie',
        'gloria'   => 'gloria',
        'sanctus'  => 'sanctus',
        'agnus'    => 'agnus',
        'anamnese' => 'anamnese',
        'alleluia' => 'alleluia',
    ];

    public static function urlListe(): string
    {
        return ChoralePoleFontainebleau::BASE_URL . '/category/bibliotheque/messes/';
    }

    /**
     * Extrait la liste des fiches de la catégorie « Messes ». Contrairement à
     * /category/bibliotheque/chants/ (tableau « index-chants » dédié), cette
     * catégorie affiche une simple liste d'articles WordPress — mais le thème
     * l'affiche en entier quelle que soit la page demandée (vérifié jusqu'à
     * /page/10/ : mêmes fiches à chaque fois) : on se contente donc de la
     * première page, sans paginer.
     *
     * @return array<string,array{ref:string,url:string,titre:string}> indexé par ref, dédoublonné.
     */
    public static function parseListe(string $html): array
    {
        if (!preg_match_all(
            '~<h5\s+itemprop="headline">\s*<a\s+href="([^"]+)"[^>]*>\s*(.*?)</a>~s',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            return [];
        }

        $fiches = [];
        foreach ($matches as $m) {
            $url = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $ref = ChoralePoleFontainebleau::ref($url);
            if ($ref === '' || isset($fiches[$ref])) {
                continue;
            }
            $fiches[$ref] = ['ref' => $ref, 'url' => $url, 'titre' => Paroles::ligne($m[2])];
        }

        return $fiches;
    }

    /**
     * Déduit, à partir du titre d'une fiche, la partie liturgique (slug
     * App\SectionTypes) et le nom de l'ordinaire, ou null si le titre ne
     * désigne pas explicitement une messe.
     *
     * @return array{type:string,ordinaire:string}|null
     */
    public static function detecter(string $titre): ?array
    {
        $titre = trim($titre);
        $sansAccents = Paroles::sansAccents(mb_strtolower($titre, 'UTF-8'));

        foreach (self::PARTIES as $motif => $type) {
            if (!str_starts_with($sansAccents, $motif)) {
                continue;
            }

            // sansAccents() transforme caractère à caractère (aucun accent dans
            // les motifs eux-mêmes) : la longueur du motif reste donc un
            // décalage valable dans le titre original, accents compris.
            $reste = trim(mb_substr($titre, mb_strlen($motif), null, 'UTF-8'));
            if (!preg_match('~^messe\b~iu', $reste)) {
                return null;
            }

            // On ne garde que la casse du site pour le reste du nom (déjà
            // propre dans la grande majorité des fiches — « Divine Miséricorde »,
            // « Trinité »… — et invérifiable ici) ; seul « messe »/« Messe »
            // lui-même est normalisé, la source l'écrivant tantôt en minuscules.
            return ['type' => $type, 'ordinaire' => 'Messe' . mb_substr($reste, 5, null, 'UTF-8')];
        }

        return null;
    }
}
