<?php

declare(strict_types=1);

/**
 * Import des ordinaires de messe de la catégorie « Messes » du site « Chorale
 * Paroissiale du Pôle Missionnaire de Fontainebleau »
 * (https://choralepolefontainebleau.org/category/bibliotheque/messes/) —
 * voir bin/import_emmanuel_ordinaires.php pour l'équivalent sur emmanuel.info.
 *
 * Contrairement à emmanuel.info, chaque partie (Kyrie, Gloria, Alléluia,
 * Sanctus, Anamnèse, Agnus) a ici sa PROPRE fiche, au même gabarit que les
 * chants « normaux » du site (App\Import\ChoralePoleFontainebleau::parseChant,
 * réutilisé ici) — et les fiches d'un même ordinaire ne sont pas reliées
 * entre elles de façon fiable sur le site (issue #12) : le rapprochement se
 * fait donc uniquement par le titre (« Sanctus messe du partage » → partie
 * « sanctus » de l'ordinaire « Messe du partage »), voir
 * App\Import\ChoralePoleFontainebleauOrdinaires::detecter.
 *
 * Une seule passe (comme emmanuel-ordinaires) qui écrit directement dans le
 * répertoire (App\Models\RepertoireChant::upsertDepuisImport) : le
 * dédoublonnage habituel en 2 passes (voir bin/import_repertoire.php) n'est
 * pas adapté ici, une ligne import_journal par fiche source suffit
 * (source = chorale-pole-fontainebleau-ordinaires).
 *
 * Usage :
 *   php bin/import_chorale_pole_fontainebleau_ordinaires.php
 *   php bin/import_chorale_pole_fontainebleau_ordinaires.php --limit=20 --dry-run
 *
 * Options :
 *   --limit=N    S'arrêter après N fiches téléchargées.
 *   --delay=MS   Pause minimale entre requêtes HTTP en ms (défaut : 1000).
 *   --refresh    Relance même les fiches déjà importées (par défaut, ne
 *                retélécharge que les fiches jamais vues ou en erreur).
 *   --dry-run    N'écrit rien en base.
 *   --quiet      Affiche moins de messages.
 *   -h, --help   Cette aide.
 */

use App\Database;
use App\Import\ChoralePoleFontainebleau as Site;
use App\Import\ChoralePoleFontainebleauOrdinaires as Ordinaires;
use App\Import\Paroles;
use App\Models\Chant;
use App\Models\RepertoireChant;
use App\SectionTypes;

const SOURCE = 'chorale-pole-fontainebleau-ordinaires';

if (PHP_SAPI !== 'cli') {
    exit("Ce script s'exécute en ligne de commande uniquement.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

$opts = getopt('h', ['limit:', 'delay:', 'refresh', 'dry-run', 'quiet', 'help']);
if (isset($opts['h']) || isset($opts['help'])) {
    $doc = (string) file_get_contents(__FILE__);
    echo substr($doc, (int) strpos($doc, '/**'), (int) strpos($doc, '*/') + 2 - (int) strpos($doc, '/**')), "\n";
    exit(0);
}

$limit   = isset($opts['limit']) ? max(0, (int) $opts['limit']) : 0;
$delayMs = isset($opts['delay']) ? max(0, (int) $opts['delay']) : 1000;
$refresh = isset($opts['refresh']);
$dryRun  = isset($opts['dry-run']);
$quiet   = isset($opts['quiet']);

$log = static function (string $msg) use ($quiet): void {
    if (!$quiet) {
        echo $msg, "\n";
    }
};

[$status, $body] = httpGet(Ordinaires::urlListe(), $delayMs);
if ($status !== 200 || $body === null) {
    exit('  ! ' . Ordinaires::urlListe() . " : HTTP {$status}\n");
}

$fiches = Ordinaires::parseListe($body);
$log(sprintf('%d fiche(s) sur %s.', count($fiches), Ordinaires::urlListe()));

$aTraiter = [];
foreach ($fiches as $ref => $fiche) {
    $match = Ordinaires::detecter($fiche['titre']);
    if ($match !== null) {
        $aTraiter[$ref] = $fiche + $match;
    }
}
$log(sprintf("%d fiche(s) reconnue(s) comme partie d'un ordinaire.", count($aTraiter)));

if (!$refresh) {
    $dejaImportees = [];
    foreach (Database::all("SELECT ref FROM import_journal WHERE source = ? AND statut = 'importe'", [SOURCE]) as $row) {
        $dejaImportees[(string) $row['ref']] = true;
    }
    $aTraiter = array_diff_key($aTraiter, $dejaImportees);
    $log(sprintf('%d fiche(s) restant à traiter (déjà importées exclues).', count($aTraiter)));
}

$fichesCreees = $fichesCompletees = $sansParoles = $erreurs = $fait = 0;

foreach ($aTraiter as $ref => $fiche) {
    // Les clés d'array numériques ("4812") sont castées en int par PHP.
    $ref = (string) $ref;

    if ($limit && $fait >= $limit) {
        $log("  (limite {$limit} atteinte)");
        break;
    }
    $fait++;

    [$status, $body] = httpGet($fiche['url'], $delayMs);
    if ($status !== 200 || $body === null) {
        $erreurs++;
        $log("  ! {$ref} : HTTP {$status}");
        continue;
    }

    $data = Site::parseChant($body);
    if ($data === null) {
        $sansParoles++;
        $log("  ! {$ref} : aucune parole exploitable ({$fiche['titre']})");
        continue;
    }

    $nomPartie     = SectionTypes::libelle($fiche['type']);
    $chantFormatte = Paroles::format($data['chant']);

    $ligne = [
        'titre'           => tronque($nomPartie . ' — ' . $fiche['ordinaire'], 255),
        'code'            => $data['code'] !== '' ? tronque($data['code'], 60) : null,
        'code_repertoire' => $data['code_repertoire'] !== null ? tronque($data['code_repertoire'], 60) : null,
        'auteur'          => $data['auteur'] !== '' ? tronque($data['auteur'], 190) : null,
        'type'            => $fiche['type'],
        'nom'             => $nomPartie,
        'ordinaire'       => tronque($fiche['ordinaire'], 190),
        'chant'           => $chantFormatte,
        'nb_couplets'     => count_couplets($chantFormatte, false),
    ];

    if ($dryRun) {
        echo sprintf(
            "    · %-10s %s (%s)%s\n",
            $fiche['type'],
            $ligne['titre'],
            $ligne['ordinaire'],
            $ligne['auteur'] !== null ? ' — ' . $ligne['auteur'] : ''
        );
        continue;
    }

    $existant = Database::one('SELECT chant_id FROM import_journal WHERE source = ? AND ref = ?', [SOURCE, $ref]);
    $dejaLie  = $existant !== null && $existant['chant_id'] !== null ? (int) $existant['chant_id'] : null;

    $dedupAvant = $dejaLie ?? RepertoireChant::trouverDoublon(
        $ligne['titre'], $ligne['chant'], $ligne['code'], $ligne['code_repertoire'], SOURCE
    );
    $repertoireId = RepertoireChant::upsertDepuisImport(SOURCE, $ligne, $dejaLie, null);
    $dedupAvant !== null ? $fichesCompletees++ : $fichesCreees++;

    Database::run(
        "INSERT INTO import_journal
            (source, ref, statut, url, titre, titre_reduit, code, code_repertoire, auteur, type, nom, ordinaire, chant, empreinte_paroles, chant_id, traite_le)
         VALUES (?, ?, 'importe', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            statut = 'importe', url = VALUES(url), titre = VALUES(titre), titre_reduit = VALUES(titre_reduit),
            code = VALUES(code), code_repertoire = VALUES(code_repertoire), auteur = VALUES(auteur),
            type = VALUES(type), nom = VALUES(nom), ordinaire = VALUES(ordinaire), chant = VALUES(chant),
            empreinte_paroles = VALUES(empreinte_paroles), chant_id = VALUES(chant_id), traite_le = NOW()",
        [
            SOURCE,
            tronque($ref, 190),
            $fiche['url'],
            $ligne['titre'],
            Chant::reduire($ligne['titre']),
            $ligne['code'],
            $ligne['code_repertoire'],
            $ligne['auteur'],
            $ligne['type'],
            $ligne['nom'],
            $ligne['ordinaire'],
            $data['chant'],
            Chant::premieresLignes($chantFormatte),
            $repertoireId,
        ]
    );

    if (!$quiet && $fait % 25 === 0) {
        echo sprintf("  … %d fiches — %d créées, %d complétées\n", $fait, $fichesCreees, $fichesCompletees);
    }
}

echo sprintf(
    "\n%d fiche(s) traitée(s) (%d erreur(s), %d sans paroles) : %d créée(s), %d complétée(s)%s.\n",
    $fait,
    $erreurs,
    $sansParoles,
    $fichesCreees,
    $fichesCompletees,
    $dryRun ? ' (dry-run)' : ''
);

exit($erreurs > 0 ? 1 : 0);

// =======================================================================
// Fonctions
// =======================================================================

/** Tronque une chaîne à $max caractères (sécurité colonnes VARCHAR). */
function tronque(string $s, int $max): string
{
    return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max)) : $s;
}

/**
 * GET HTTP avec politesse (pause minimale entre appels) et 3 tentatives.
 *
 * @return array{0:int,1:?string} [code HTTP, corps ou null]
 */
function httpGet(string $url, int $delayMs): array
{
    static $dernier = 0.0;

    $attente = $delayMs / 1000 - (microtime(true) - $dernier);
    if ($attente > 0) {
        usleep((int) ($attente * 1_000_000));
    }

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
        $dernier = microtime(true);

        if ($body !== false && $status !== 429 && $status < 500) {
            return [$status, (string) $body];
        }
        sleep(2 * $essai);
        $dernier = microtime(true);
    }

    return [$status, null];
}
