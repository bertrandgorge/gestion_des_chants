<?php

declare(strict_types=1);

/**
 * Import des chants du site « Chorale Paroissiale du Pôle Missionnaire de
 * Fontainebleau » (https://choralepolefontainebleau.org).
 *
 * Deux passes suivies dans « import_journal » (source = chorale-pole-fontainebleau),
 * toutes relançables (une exécution sans --phase les enchaîne) :
 *
 *   1. ENUM   : charge /category/bibliotheque/chants/ (un tableau y liste TOUS les
 *               chants, quelle que soit la pagination) et référence chaque chant
 *               en « a-importer » (titre, thématique et lien sont déjà connus ici).
 *   2. FETCH  : pour chaque « a-importer », charge la fiche et en déduit
 *               « avec-paroles » (données parsées) ou « sans-paroles ».
 *
 * La création / le dédoublonnage des fiches du répertoire (App\Models\RepertoireChant)
 * à partir des lignes « avec-paroles » est une passe séparée, commune à toutes
 * les sources (y compris les doublons éventuels avec chantonseneglise.fr /
 * catechisme-emmanuel.com) : voir bin/import_repertoire.php.
 *
 * Le « type » est un slug de App\SectionTypes::DEFAUT, déduit de la thématique du
 * site (« entree » par défaut).
 *
 * Usage :
 *   php bin/import_chorale_pole_fontainebleau.php
 *   php bin/import_chorale_pole_fontainebleau.php --phase=fetch --limit=50
 *
 * Options :
 *   --phase=NOM   enum | fetch : ne lancer qu'une passe (défaut : les 2).
 *   --limit=N     Passe FETCH : s'arrêter après N fiches téléchargées.
 *   --delay=MS    Pause minimale entre requêtes HTTP en ms (défaut : 2000).
 *   --refresh     Repart de zéro : remet toutes les lignes en « a-importer ».
 *   --dry-run     N'écrit rien en base.
 *   --quiet       Affiche moins de messages.
 *   -h, --help    Cette aide.
 */

use App\Database;
use App\Import\ChoralePoleFontainebleau as Site;

const SOURCE = 'chorale-pole-fontainebleau';

if (PHP_SAPI !== 'cli') {
    exit("Ce script s'exécute en ligne de commande uniquement.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

// --- Options -------------------------------------------------------------
$opts = getopt('h', ['phase:', 'limit:', 'delay:', 'refresh', 'dry-run', 'quiet', 'help']);

if (isset($opts['h']) || isset($opts['help'])) {
    $doc = (string) file_get_contents(__FILE__);
    echo substr($doc, (int) strpos($doc, '/**'), (int) strpos($doc, '*/') + 2 - (int) strpos($doc, '/**')), "\n";
    exit(0);
}

$phase = isset($opts['phase']) ? strtolower((string) $opts['phase']) : null;
if ($phase !== null && !in_array($phase, ['enum', 'fetch'], true)) {
    exit("--phase doit valoir enum ou fetch.\n");
}
$limit   = isset($opts['limit']) ? max(0, (int) $opts['limit']) : 0;
$delayMs = isset($opts['delay']) ? max(0, (int) $opts['delay']) : 500;
$dryRun  = isset($opts['dry-run']);
$quiet   = isset($opts['quiet']);

$faire = static fn (string $p): bool => $phase === null || $phase === $p;
$log = static function (string $msg) use ($quiet): void {
    if (!$quiet) {
        echo $msg, "\n";
    }
};

if (isset($opts['refresh']) && !$dryRun) {
    Database::run(
        "UPDATE import_journal
            SET statut = 'a-importer', code = NULL, code_repertoire = NULL,
                auteur = NULL, type = NULL, nom = NULL, chant = NULL
          WHERE source = ?",
        [SOURCE]
    );
    $log('--refresh : toutes les lignes remises en « a-importer ».');
}

$erreurs = 0;

// =======================================================================
// Passe 1 — ENUM : référencement de la liste
// =======================================================================
if ($faire('enum')) {
    $log("\n=== Passe 1 : référencement de la liste ===");

    [$status, $body] = httpGet(Site::urlListe(), $delayMs);
    if ($status !== 200 || $body === null) {
        exit("  ! /category/bibliotheque/chants/ : HTTP {$status}\n");
    }

    $chants = Site::parseListe($body);
    $ajoutes = $dryRun ? 0 : referenceChants($chants);
    $log(sprintf('  %d chants dans la liste, %d nouvelles lignes.', count($chants), $ajoutes));
}

// =======================================================================
// Passe 2 — FETCH : récupération des fiches
// =======================================================================
if ($faire('fetch')) {
    $log("\n=== Passe 2 : récupération des paroles ===");

    $aTraiter = Database::all(
        "SELECT ref, url, categorie FROM import_journal
          WHERE source = ? AND statut IN ('a-importer', 'erreur')
          ORDER BY CAST(ref AS UNSIGNED)",
        [SOURCE]
    );
    $log(sprintf('%d fiches à récupérer.', count($aTraiter)));

    $avecParoles = $sansParoles = $fait = 0;

    foreach ($aTraiter as $row) {
        if ($limit && $fait >= $limit) {
            $log("  (limite {$limit} atteinte)");
            break;
        }
        $fait++;
        $ref = (string) $row['ref'];
        [$status, $body] = httpGet((string) $row['url'], $delayMs);

        if ($status !== 200 || $body === null) {
            $erreurs++;
            $log("  ! {$ref} : HTTP {$status}");
            majJournalStatut($ref, $status === 404 ? 'sans-paroles' : 'erreur', $dryRun);
            continue;
        }

        $data = Site::parseChant($body, (string) ($row['categorie'] ?? ''));
        if ($data === null) {
            $sansParoles++;
            majJournalStatut($ref, 'sans-paroles', $dryRun);
            continue;
        }

        $avecParoles++;
        majJournalDonnees($ref, [
            'titre'           => tronque($data['titre'], 255),
            'code'            => $data['code'] !== '' ? tronque($data['code'], 60) : null,
            'code_repertoire' => $data['code_repertoire'] !== null ? tronque($data['code_repertoire'], 60) : null,
            'auteur'          => $data['auteur'] !== '' ? tronque($data['auteur'], 190) : null,
            'type'            => $data['type'],
            'categorie'       => tronque($data['theme'], 255),
            'nom'             => tronque($data['nom'], 120),
            'chant'           => $data['chant'],
        ], $dryRun);

        if (!$quiet && $fait % 25 === 0) {
            echo sprintf("  … %d fiches — %d avec paroles, %d sans\n", $fait, $avecParoles, $sansParoles);
        }
    }

    $log(sprintf('Passe 2 terminée : %d avec paroles, %d sans paroles.', $avecParoles, $sansParoles));
}

// --- Bilan --------------------------------------------------------------
echo "\nÉtat du journal (import_journal) :\n";
$bilan = Database::all(
    "SELECT statut, COUNT(*) n FROM import_journal WHERE source = ? GROUP BY statut ORDER BY statut",
    [SOURCE]
);
if ($bilan === []) {
    echo "  (vide)\n";
}
foreach ($bilan as $r) {
    echo sprintf("  %-14s : %d\n", $r['statut'], $r['n']);
}
if ($erreurs > 0) {
    echo sprintf("  %-14s : %d\n", 'erreurs HTTP', $erreurs);
}

exit($erreurs > 0 ? 1 : 0);

// =======================================================================
// Fonctions
// =======================================================================

/**
 * Insère les chants de la liste dans import_journal (statut « a-importer »).
 * Purement additif : ON DUPLICATE KEY rafraîchit l'url / le titre / la thématique,
 * jamais le statut ni les paroles déjà récupérées.
 *
 * @param array<string,array{ref:string,url:string,titre:string,theme:string}> $chants
 */
function referenceChants(array $chants): int
{
    if ($chants === []) {
        return 0;
    }

    $avant = (int) Database::value('SELECT COUNT(*) FROM import_journal WHERE source = ?', [SOURCE]);

    foreach (array_chunk($chants, 100) as $lot) {
        $valeurs = [];
        $params  = [];
        foreach ($lot as $c) {
            $valeurs[] = '(?, ?, ?, ?, ?, ?)';
            array_push(
                $params,
                SOURCE,
                tronque($c['ref'], 190),
                'a-importer',
                $c['url'],
                tronque($c['titre'], 255),
                $c['theme'] !== '' ? tronque($c['theme'], 255) : null
            );
        }
        Database::run(
            'INSERT INTO import_journal (source, ref, statut, url, titre, categorie) VALUES '
            . implode(', ', $valeurs)
            . ' ON DUPLICATE KEY UPDATE url = VALUES(url), titre = VALUES(titre),
                 categorie = VALUES(categorie)',
            $params
        );
    }

    return (int) Database::value('SELECT COUNT(*) FROM import_journal WHERE source = ?', [SOURCE]) - $avant;
}

function majJournalStatut(string $ref, string $statut, bool $dryRun): void
{
    if ($dryRun) {
        return;
    }
    Database::run(
        "UPDATE import_journal SET statut = ?, traite_le = NOW() WHERE source = ? AND ref = ?",
        [$statut, SOURCE, $ref]
    );
}

/** @param array<string,mixed> $donnees */
function majJournalDonnees(string $ref, array $donnees, bool $dryRun): void
{
    if ($dryRun) {
        return;
    }
    Database::update(
        'import_journal',
        ['statut' => 'avec-paroles', 'traite_le' => date('Y-m-d H:i:s')] + $donnees,
        ['source' => SOURCE, 'ref' => $ref]
    );
}

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
