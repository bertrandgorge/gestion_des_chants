<?php

declare(strict_types=1);

namespace App\Import;

use App\Database;
use App\Models\Chant;
use App\Models\RepertoireChant;

/**
 * Import ponctuel d'un seul chant, à partir de son URL.
 *
 * Reconnaît les trois sites gérés par les scripts d'import de masse et réutilise
 * leur analyse (App\Import\*) :
 *
 *   - https://www.chantonseneglise.fr/chant/{id}/{slug}   (ou /voir-texte/{id})
 *   - https://catechisme-emmanuel.com/chants/{slug}/
 *   - https://choralepolefontainebleau.org/bibliotheque/…/{slug}-{id}/
 *
 * Crée — ou complète, si le chant existe déjà (dédoublonnage par
 * App\Models\RepertoireChant::trouverDoublon, ou relance sur la même URL) —
 * une fiche du répertoire, et trace l'opération dans « import_journal »
 * (statut « importe »), de sorte que bin/import_repertoire.php la prenne
 * aussi en compte.
 *
 * Utilisé à la fois par bin/import_url.php (CLI) et par
 * App\Controllers\RepertoireController::importer() (import depuis l'interface).
 */
final class UrlImporter
{
    /**
     * @return array{ok:bool,message:string,id:?int}
     */
    public static function importer(string $url, bool $dryRun = false): array
    {
        $cible = self::resoudre($url);
        if ($cible === null) {
            return [
                'ok' => false,
                'message' => "Site non reconnu. Sites gérés : chantonseneglise.fr, "
                    . 'catechisme-emmanuel.com, choralepolefontainebleau.org.',
                'id' => null,
            ];
        }

        [$status, $body] = self::httpGet($cible['fetch']);
        if ($status !== 200 || $body === null) {
            return ['ok' => false, 'message' => "{$cible['fetch']} : HTTP {$status}", 'id' => null];
        }

        $data = ($cible['parse'])($body);
        if ($data === null) {
            return ['ok' => false, 'message' => 'Pas de paroles exploitables sur cette fiche.', 'id' => null];
        }

        $chant    = Paroles::format($data['chant']);
        $couplets = count_couplets($chant, false);

        $ligne = [
            'titre'           => self::tronque($data['titre'], 255),
            'code'            => $data['code'] !== '' ? self::tronque($data['code'], 60) : null,
            'code_repertoire' => $data['code_repertoire'] !== null ? self::tronque($data['code_repertoire'], 60) : null,
            'auteur'          => $data['auteur'] !== '' ? self::tronque($data['auteur'], 190) : null,
            'type'            => $data['type'] !== '' ? $data['type'] : TypeLiturgique::DEFAUT,
            'nom'             => $data['nom'] !== '' ? self::tronque($data['nom'], 120) : 'Chant',
            'chant'           => $chant,
            'nb_couplets'     => $couplets,
        ];

        if ($dryRun) {
            $doublon = RepertoireChant::trouverDoublon(
                $ligne['titre'],
                $ligne['chant'],
                $ligne['code'],
                $ligne['code_repertoire'],
                $cible['source']
            );

            return [
                'ok' => true,
                'message' => sprintf(
                    "Source « %s », ref « %s ».\n\n  Titre    : %s\n  Type     : %s (%s)\n  Cote     : %s\n"
                    . "  Auteur   : %s\n  Couplets : %d\n  Paroles  :\n%s\n\n--dry-run : rien écrit.%s",
                    $cible['source'],
                    $cible['ref'],
                    $ligne['titre'],
                    $ligne['type'],
                    $ligne['nom'],
                    $ligne['code'] ?? '—',
                    $ligne['auteur'] ?? '—',
                    $couplets,
                    (string) preg_replace('~^~m', '    | ', $chant),
                    $doublon !== null ? " (doublon de la fiche #{$doublon})" : ''
                ),
                'id' => null,
            ];
        }

        $existant = Database::one(
            'SELECT chant_id FROM import_journal WHERE source = ? AND ref = ?',
            [$cible['source'], $cible['ref']]
        );
        $dejaLie = $existant !== null && $existant['chant_id'] !== null ? (int) $existant['chant_id'] : null;

        // La catégorie brute de chantonseneglise n'est pas fiable comme mot-clé.
        $motCle = $cible['source'] !== 'chantonseneglise' && $data['categorie'] !== ''
            ? self::tronque($data['categorie'], 255)
            : null;

        $dedupAvant   = $dejaLie ?? RepertoireChant::trouverDoublon(
            $ligne['titre'],
            $ligne['chant'],
            $ligne['code'],
            $ligne['code_repertoire'],
            $cible['source']
        );
        $repertoireId = RepertoireChant::upsertDepuisImport($cible['source'], $ligne, $dejaLie, $motCle);

        // « chant » garde les paroles brutes (comme les passes ENUM/FETCH des
        // scripts de masse) : la mise en forme ($chant, ci-dessus) ne sert qu'à
        // construire la fiche du répertoire, jamais stockée telle quelle ici.
        Database::run(
            "INSERT INTO import_journal
                (source, ref, statut, url, titre, titre_reduit, code, code_repertoire, auteur, type, categorie, nom, chant, empreinte_paroles, chant_id, traite_le)
             VALUES (?, ?, 'importe', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                statut = 'importe', url = VALUES(url), titre = VALUES(titre), titre_reduit = VALUES(titre_reduit),
                code = VALUES(code), code_repertoire = VALUES(code_repertoire), auteur = VALUES(auteur),
                type = VALUES(type), categorie = VALUES(categorie), nom = VALUES(nom), chant = VALUES(chant),
                empreinte_paroles = VALUES(empreinte_paroles), chant_id = VALUES(chant_id), traite_le = NOW()",
            [
                $cible['source'],
                self::tronque($cible['ref'], 190),
                $cible['url'],
                $ligne['titre'],
                Chant::reduire($ligne['titre']),
                $ligne['code'],
                $data['code_repertoire'] !== null ? self::tronque($data['code_repertoire'], 60) : null,
                $ligne['auteur'],
                $ligne['type'],
                $data['categorie'] !== '' ? self::tronque($data['categorie'], 255) : null,
                $ligne['nom'],
                $data['chant'],
                Chant::premieresLignes($chant),
                $repertoireId,
            ]
        );

        $verbe = $dejaLie !== null
            ? 'mise à jour'
            : ($dedupAvant !== null ? "fusionnée avec la fiche existante #{$dedupAvant}" : 'créée');

        return ['ok' => true, 'message' => "Fiche #{$repertoireId} {$verbe}.", 'id' => $repertoireId];
    }

    /**
     * Reconnaît le site d'après l'URL et renvoie de quoi récupérer / analyser la
     * fiche. La closure « parse » ramène le résultat spécifique à chaque site à une
     * forme commune.
     *
     * @return array{
     *     source:string, ref:string, url:string, fetch:string,
     *     parse:callable(string):(array{titre:string,code:string,auteur:string,type:string,nom:string,categorie:string,code_repertoire:?string,chant:string}|null)
     * }|null
     */
    public static function resoudre(string $url): ?array
    {
        $parts = parse_url(trim($url));
        if ($parts === false || !isset($parts['host'], $parts['path'])) {
            return null;
        }
        $host = strtolower(preg_replace('~^www\.~', '', $parts['host']) ?? '');
        $path = $parts['path'];

        if ($host === 'chantonseneglise.fr') {
            if (!preg_match('~/(?:chant|voir-texte)/(\d+)~', $path, $m)) {
                return null;
            }
            $id   = (int) $m[1];
            $slug = preg_match('~/chant/\d+/([^/?#]+)~', $path, $s) ? $s[1] : '';

            return [
                'source' => 'chantonseneglise',
                'ref'    => (string) $id,
                'url'    => $slug !== '' ? ChantonsEnEglise::urlChant($id, $slug) : ChantonsEnEglise::urlVoirTexte($id),
                'fetch'  => ChantonsEnEglise::urlVoirTexte($id),
                'parse'  => static function (string $body): ?array {
                    $d = ChantonsEnEglise::parseVoirTexte($body);
                    if ($d === null) {
                        return null;
                    }

                    return [
                        'titre'           => $d['titre'],
                        'code'            => $d['code'],
                        'auteur'          => $d['auteur'],
                        'type'            => $d['type'],
                        'nom'             => ChantonsEnEglise::nomSection($d['categorie'], $d['type']),
                        'categorie'       => $d['categorie'],
                        'code_repertoire' => $d['code_repertoire'],
                        'chant'           => $d['chant'],
                    ];
                },
            ];
        }

        if ($host === 'catechisme-emmanuel.com') {
            if (!preg_match('~/chants/([^/?#]+)~', $path, $m)) {
                return null;
            }
            $slug = $m[1];

            return [
                'source' => 'catechisme-emmanuel',
                'ref'    => $slug,
                'url'    => CatechismeEmmanuel::urlChant($slug),
                'fetch'  => CatechismeEmmanuel::urlChant($slug),
                'parse'  => static function (string $body): ?array {
                    $d = CatechismeEmmanuel::parseChant($body);
                    if ($d === null) {
                        return null;
                    }

                    return [
                        'titre'           => $d['titre'],
                        'code'            => '',
                        'auteur'          => $d['auteur'],
                        'type'            => $d['type'],
                        'nom'             => $d['nom'],
                        'categorie'       => $d['theme'],
                        'code_repertoire' => $d['code_repertoire'],
                        'chant'           => $d['chant'],
                    ];
                },
            ];
        }

        if ($host === 'choralepolefontainebleau.org') {
            $ref = ChoralePoleFontainebleau::ref($url);
            if ($ref === '') {
                return null;
            }
            $canonique = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . $path;

            return [
                'source' => 'chorale-pole-fontainebleau',
                'ref'    => $ref,
                'url'    => $canonique,
                'fetch'  => $canonique,
                'parse'  => static function (string $body): ?array {
                    $d = ChoralePoleFontainebleau::parseChant($body);
                    if ($d === null) {
                        return null;
                    }

                    return [
                        'titre'           => $d['titre'],
                        'code'            => $d['code'],
                        'auteur'          => $d['auteur'],
                        'type'            => $d['type'],
                        'nom'             => $d['nom'],
                        'categorie'       => $d['theme'],
                        'code_repertoire' => $d['code_repertoire'],
                        'chant'           => $d['chant'],
                    ];
                },
            ];
        }

        return null;
    }

    /** Tronque une chaîne à $max caractères (sécurité colonnes VARCHAR). */
    private static function tronque(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max)) : $s;
    }

    /**
     * GET HTTP avec 3 tentatives.
     *
     * @return array{0:int,1:?string} [code HTTP, corps ou null]
     */
    private static function httpGet(string $url): array
    {
        $status = 0;
        for ($essai = 1; $essai <= 3; $essai++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT      => 'GestionDesChants/1.0 (+import catalogue paroisse)',
                CURLOPT_HTTPHEADER     => ['Accept: text/html'],
                CURLOPT_ENCODING       => '',
            ]);
            $body   = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($body !== false && $status !== 429 && $status < 500) {
                return [$status, (string) $body];
            }
            sleep(2 * $essai);
        }

        return [$status, null];
    }
}
