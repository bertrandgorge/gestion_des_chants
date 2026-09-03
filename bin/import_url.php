<?php

declare(strict_types=1);

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
 * Crée — ou met à jour, si l'URL a déjà été importée — une fiche de catalogue
 * dans « chants » (feuille_id = NULL) et trace l'opération dans « import_journal »
 * (statut « importe »), de sorte que les --reformat / --refresh des scripts de
 * masse la prennent aussi en compte.
 *
 * Dédoublonnage : si une fiche du même chant existe déjà (clé titre + première
 * ligne du refrain, voir App\Models\Chant::cleDedup), elle est complétée au lieu
 * d'être dupliquée. Pour regrouper des doublons déjà en base : bin/dedup_chants.php.
 *
 * Usage :
 *   php bin/import_url.php <url>
 *   php bin/import_url.php <url> --dry-run
 *
 * Options :
 *   --dry-run   Analyse et affiche la fiche sans rien écrire en base.
 *   --quiet     Affiche moins de messages.
 *   -h, --help  Cette aide.
 */

use App\Database;
use App\Import\CatechismeEmmanuel;
use App\Import\ChantonsEnEglise;
use App\Import\ChoralePoleFontainebleau;
use App\Import\Paroles;
use App\Import\TypeLiturgique;

if (PHP_SAPI !== 'cli') {
    exit("Ce script s'exécute en ligne de commande uniquement.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

// --- Arguments ----------------------------------------------------------
$flags = [];
$url   = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '-')) {
        $flags[ltrim($arg, '-')] = true;
    } elseif ($url === null) {
        $url = $arg;
    }
}

if (isset($flags['h']) || isset($flags['help'])) {
    $doc = (string) file_get_contents(__FILE__);
    echo substr($doc, (int) strpos($doc, '/**'), (int) strpos($doc, '*/') + 2 - (int) strpos($doc, '/**')), "\n";
    exit(0);
}

if ($url === null) {
    exit("Usage : php bin/import_url.php <url> [--dry-run] [--quiet]\n");
}

$dryRun = isset($flags['dry-run']);
$quiet  = isset($flags['quiet']);
$log = static function (string $msg) use ($quiet): void {
    if (!$quiet) {
        echo $msg, "\n";
    }
};

// --- Résolution de la source ------------------------------------------
$cible = resoudreSource($url);
if ($cible === null) {
    exit(
        "Site non reconnu. Sites gérés :\n"
        . "  - chantonseneglise.fr\n"
        . "  - catechisme-emmanuel.com\n"
        . "  - choralepolefontainebleau.org\n"
    );
}

$log(sprintf('Source « %s », ref « %s ».', $cible['source'], $cible['ref']));

[$status, $body] = httpGet($cible['fetch']);
if ($status !== 200 || $body === null) {
    exit("  ! {$cible['fetch']} : HTTP {$status}\n");
}

$data = ($cible['parse'])($body);
if ($data === null) {
    exit("  ! Pas de paroles exploitables sur cette fiche.\n");
}

$chant    = Paroles::format($data['chant']);
$couplets = count_couplets($chant, false);

$ligne = [
    'feuille_id'  => null,
    'nom'         => $data['nom'] !== '' ? $data['nom'] : 'Chant',
    'type'        => $data['type'] !== '' ? $data['type'] : TypeLiturgique::DEFAUT,
    'position'    => 0,
    'titre'       => tronque($data['titre'], 255),
    'code'        => $data['code'] !== '' ? tronque($data['code'], 60) : null,
    'auteur'      => $data['auteur'] !== '' ? tronque($data['auteur'], 190) : null,
    'chant'       => $chant,
    'nb_couplets' => $couplets,
    'url'         => $cible['url'],
];

$log('');
$log(sprintf('  Titre    : %s', $ligne['titre']));
$log(sprintf('  Type     : %s (%s)', $ligne['type'], $ligne['nom']));
$log(sprintf('  Cote     : %s', $ligne['code'] ?? '—'));
$log(sprintf('  Auteur   : %s', $ligne['auteur'] ?? '—'));
$log(sprintf('  Couplets : %d', $couplets));
$log(sprintf("  Paroles  :\n%s", preg_replace('~^~m', '    | ', $chant)));
$log('');

$existant = Database::one(
    'SELECT chant_id FROM import_journal WHERE source = ? AND ref = ?',
    [$cible['source'], $cible['ref']]
);
$dejaLie = $existant !== null && $existant['chant_id'] !== null ? (int) $existant['chant_id'] : null;

// Dédoublonnage : une fiche de catalogue du même chant (titre + 1re ligne du
// refrain) existe peut-être déjà, importée d'une autre source. On la complète
// plutôt que d'en créer une nouvelle.
$doublon = App\Models\Chant::catalogueParCle($ligne['titre'], $chant, $dejaLie);

if ($dryRun) {
    echo '--dry-run : rien écrit.'
        . ($dejaLie !== null ? " (déjà lié à la fiche #{$dejaLie})"
            : ($doublon !== null ? " (doublon de la fiche #{$doublon})" : ''))
        . "\n";
    exit(0);
}

if ($dejaLie !== null) {
    Database::update('chants', $ligne, ['id' => $dejaLie]);
    $chantId = $dejaLie;
    $verbe   = 'mise à jour';
} elseif ($doublon !== null) {
    fusionner($doublon, $ligne);
    $chantId = $doublon;
    $verbe   = "fusionnée avec la fiche existante #{$doublon}";
} else {
    $chantId = Database::insert('chants', $ligne);
    $verbe   = 'créée';
}

Database::run(
    "INSERT INTO import_journal
        (source, ref, statut, url, titre, code, code_repertoire, auteur, type, categorie, nom, chant, chant_id, traite_le)
     VALUES (?, ?, 'importe', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE
        statut = 'importe', url = VALUES(url), titre = VALUES(titre), code = VALUES(code),
        code_repertoire = VALUES(code_repertoire), auteur = VALUES(auteur), type = VALUES(type),
        categorie = VALUES(categorie), nom = VALUES(nom), chant = VALUES(chant),
        chant_id = VALUES(chant_id), traite_le = NOW()",
    [
        $cible['source'],
        tronque($cible['ref'], 190),
        $cible['url'],
        $ligne['titre'],
        $ligne['code'],
        $data['code_repertoire'] !== null ? tronque($data['code_repertoire'], 60) : null,
        $ligne['auteur'],
        $ligne['type'],
        $data['categorie'] !== '' ? tronque($data['categorie'], 255) : null,
        tronque($data['nom'] !== '' ? $data['nom'] : 'Chant', 120),
        $chant,
        $chantId,
    ]
);

echo sprintf("Fiche #%d %s.\n", $chantId, $verbe);
exit(0);

// =======================================================================
// Fonctions
// =======================================================================

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
function resoudreSource(string $url): ?array
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
function tronque(string $s, int $max): string
{
    return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max)) : $s;
}

/**
 * Complète une fiche de catalogue existante avec les données de la nouvelle
 * source : paroles plus complètes (plus de couplets), cote / auteur manquants,
 * type resté au défaut. L'URL et le classement déjà en place sont conservés.
 *
 * @param array<string,mixed> $nouveau  ligne prête pour « chants »
 */
function fusionner(int $chantId, array $nouveau): void
{
    $actuel = Database::one(
        'SELECT type, nom, code, auteur, chant, nb_couplets FROM chants WHERE id = ?',
        [$chantId]
    );
    if ($actuel === null) {
        return;
    }

    $scoreNouveau = App\Models\Chant::scoreQualite((string) $nouveau['chant']);
    $scoreActuel  = App\Models\Chant::scoreQualite((string) $actuel['chant']);

    $maj = [];
    if ($scoreNouveau < $scoreActuel
        || ((int) $nouveau['nb_couplets'] > (int) $actuel['nb_couplets'] && $scoreNouveau <= $scoreActuel)
    ) {
        $maj['chant']       = $nouveau['chant'];
        $maj['nb_couplets'] = $nouveau['nb_couplets'];
    }
    if (trim((string) $actuel['code']) === '' && (string) ($nouveau['code'] ?? '') !== '') {
        $maj['code'] = $nouveau['code'];
    }
    if (trim((string) $actuel['auteur']) === '' && (string) ($nouveau['auteur'] ?? '') !== '') {
        $maj['auteur'] = $nouveau['auteur'];
    }
    if ($actuel['type'] === \App\Import\TypeLiturgique::DEFAUT
        && $nouveau['type'] !== '' && $nouveau['type'] !== \App\Import\TypeLiturgique::DEFAUT
    ) {
        $maj['type'] = $nouveau['type'];
        $maj['nom']  = $nouveau['nom'];
    }

    if ($maj !== []) {
        Database::update('chants', $maj, ['id' => $chantId]);
    }
}

/**
 * GET HTTP avec 3 tentatives.
 *
 * @return array{0:int,1:?string} [code HTTP, corps ou null]
 */
function httpGet(string $url): array
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
