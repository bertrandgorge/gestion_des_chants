<?php

declare(strict_types=1);

/**
 * Import des chants du site « Chantons en Église » (https://www.chantonseneglise.fr).
 *
 * Le site étant lent, l'import se déroule en deux passes, toutes relançables et
 * suivies dans la table « import_journal » (source = chantonseneglise) :
 *
 *   1. ENUM   : parcourt le catalogue par lettre (/catalogue/chant/A … Z, avec
 *               redécoupage quand une page dépasse 1000 résultats) et référence
 *               chaque chant en « a-importer » (aucune requête sur les fiches).
 *   2. FETCH  : pour chaque « a-importer », charge /voir-texte/{id}, en déduit
 *               « avec-paroles » (données parsées stockées dans import_journal)
 *               ou « sans-paroles ».
 *
 * La création / le dédoublonnage des fiches du répertoire (App\Models\RepertoireChant)
 * à partir des lignes « avec-paroles » est une passe séparée, commune à toutes
 * les sources : voir bin/import_repertoire.php.
 *
 * Le « type » est toujours un slug de App\SectionTypes::DEFAUT (entree, kyrie,
 * communion, envoi…), déduit du libellé du site (« entree » par défaut).
 *
 * Une exécution sans --phase enchaîne les deux passes ; chacune reprend où la
 * précédente s'était arrêtée. import_journal n'est JAMAIS vidée ni réinitialisée :
 * ENUM n'y ajoute que les lignes manquantes (un marqueur « enum:<préfixe> » est
 * posé par sous-préfixe entièrement parcouru pour ne pas le refaire). Seul
 * --refresh remet les compteurs à zéro, sur demande explicite.
 *
 * Usage :
 *   php bin/import_chantonseneglise.php
 *   php bin/import_chantonseneglise.php --phase=fetch --limit=200
 *   php bin/import_chantonseneglise.php --letters=A-C
 *
 * Options :
 *   --phase=NOM      enum | fetch : ne lancer qu'une passe (défaut : les 2).
 *   --letters=LISTE  Lettres / préfixes à énumérer (défaut : 0-9,A-Z).
 *                    Accepte des plages (A-G) et des préfixes (LE).
 *   --limit=N        Passe FETCH : s'arrêter après N fiches téléchargées.
 *   --delay=MS       Pause minimale entre requêtes HTTP en ms (défaut : 2000).
 *   --refresh        Repart de zéro : remet toutes les lignes en « a-importer »
 *                    et ré-énumère le catalogue.
 *   --retry-sans-paroles  Passe FETCH : re-teste aussi les « sans-paroles ».
 *   --dry-run        N'écrit rien en base.
 *   --quiet          Affiche moins de messages.
 *   -h, --help       Cette aide.
 */

use App\Database;
use App\Import\ChantonsEnEglise as Site;

const SOURCE = 'chantonseneglise';

if (PHP_SAPI !== 'cli') {
    exit("Ce script s'exécute en ligne de commande uniquement.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

// --- Options -------------------------------------------------------------
$opts = getopt('h', [
    'phase:', 'letters:', 'limit:', 'delay:',
    'refresh', 'retry-sans-paroles', 'dry-run', 'quiet', 'help',
]);

if (isset($opts['h']) || isset($opts['help'])) {
    $doc = (string) file_get_contents(__FILE__);
    echo substr($doc, (int) strpos($doc, '/**'), (int) strpos($doc, '*/') + 2 - (int) strpos($doc, '/**')), "\n";
    exit(0);
}

$phase   = isset($opts['phase']) ? strtolower((string) $opts['phase']) : null;
if ($phase !== null && !in_array($phase, ['enum', 'fetch'], true)) {
    exit("--phase doit valoir enum ou fetch.\n");
}
$lettres = expandLettres($opts['letters'] ?? '0-9,A-Z');
$limit   = isset($opts['limit']) ? max(0, (int) $opts['limit']) : 0;
$delayMs = isset($opts['delay']) ? max(0, (int) $opts['delay']) : 2000;
$refresh = isset($opts['refresh']);
$retrySP = isset($opts['retry-sans-paroles']);
$dryRun  = isset($opts['dry-run']);
$quiet   = isset($opts['quiet']);

$faire = static fn (string $p): bool => $phase === null || $phase === $p;

$log = static function (string $msg) use ($quiet): void {
    if (!$quiet) {
        echo $msg, "\n";
    }
};

// --- Réinitialisation (--refresh) --------------------------------------
if ($refresh && !$dryRun) {
    Database::run(
        "UPDATE import_journal
            SET statut = 'a-importer', titre = NULL, code = NULL, code_repertoire = NULL,
                auteur = NULL, type = NULL, nom = NULL, chant = NULL
          WHERE source = ? AND statut <> 'enumere'",
        [SOURCE]
    );
    Database::run("DELETE FROM import_journal WHERE source = ? AND statut = 'enumere'", [SOURCE]);
    $log('--refresh : toutes les lignes remises en « a-importer ».');
}

$erreurs = 0;

// =======================================================================
// Passe 1 — ENUM : référencement du catalogue
// =======================================================================
// Purement additif : n'insère que les lignes manquantes (INSERT ... ON DUPLICATE
// KEY UPDATE), ne supprime ni ne réinitialise jamais rien. Un marqueur « enumere »
// est posé pour chaque préfixe entièrement parcouru (y compris les sous-préfixes
// du redécoupage) : une reprise ne re-parcourt que ce qui restait à faire.
if ($faire('enum')) {
    $log("\n=== Passe 1 : référencement du catalogue ===");

    $enumFaits = array_fill_keys(array_column(Database::all(
        "SELECT ref FROM import_journal WHERE source = ? AND statut = 'enumere'",
        [SOURCE]
    ), 'ref'), true);

    $stats = ['vus' => 0, 'ajoutes' => 0, 'sautes' => 0];
    foreach ($lettres as $prefixe) {
        collecteCatalogue($prefixe, 1, $delayMs, $dryRun, $enumFaits, $stats, $erreurs, $log);
    }

    $log(sprintf(
        '  %d chants au catalogue, %d nouvelles lignes, %d préfixes déjà faits.',
        $stats['vus'], $stats['ajoutes'], $stats['sautes']
    ));
}

// =======================================================================
// Passe 2 — FETCH : récupération des fiches /voir-texte
// =======================================================================
if ($faire('fetch')) {
    $log("\n=== Passe 2 : récupération des paroles ===");

    $statuts = ['a-importer', 'erreur'];
    if ($retrySP) {
        $statuts[] = 'sans-paroles';
    }
    $in = implode(',', array_fill(0, count($statuts), '?'));
    $aTraiter = Database::all(
        "SELECT ref, url FROM import_journal
          WHERE source = ? AND statut IN ($in)
          ORDER BY CAST(ref AS UNSIGNED)",
        [SOURCE, ...$statuts]
    );
    $log(sprintf('%d fiches à récupérer.', count($aTraiter)));

    $avecParoles = $sansParoles = $fait = 0;

    foreach ($aTraiter as $row) {
        if ($limit && $fait >= $limit) {
            $log("  (limite {$limit} atteinte)");
            break;
        }
        $fait++;
        $ref = (int) $row['ref'];
        [$status, $body] = httpGet(Site::urlVoirTexte($ref), $delayMs);

        if ($status !== 200 || $body === null) {
            $erreurs++;
            $log("  ! {$ref} : HTTP {$status}");
            majJournalStatut($ref, $status === 404 ? 'sans-paroles' : 'erreur', $dryRun);
            continue;
        }

        $data = Site::parseVoirTexte($body);
        if ($data === null) {
            $sansParoles++;
            majJournalStatut($ref, 'sans-paroles', $dryRun);
            continue;
        }

        $avecParoles++;
        majJournalDonnees($ref, [
            'titre'           => tronque($data['titre'], 255),
            'code'            => tronque($data['code'], 60),
            'code_repertoire' => $data['code_repertoire'] !== null ? tronque($data['code_repertoire'], 60) : null,
            'auteur'          => tronque($data['auteur'], 190),
            'type'            => $data['type'],
            'categorie'       => tronque($data['categorie'], 255),
            'nom'             => tronque(Site::nomSection($data['categorie'], $data['type']), 120),
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
 * Parcourt récursivement le catalogue pour un préfixe (redécoupage si la page est
 * tronquée), en référençant les chants manquants au fur et à mesure. Pose un
 * marqueur « enum:<préfixe> » dès qu'un sous-arbre est entièrement parcouru, pour
 * qu'une reprise le saute. Renvoie false si quelque chose est resté à faire.
 *
 * @param array<string,bool> $enumFaits  marqueurs connus (mis à jour par référence)
 * @param array<string,int>  $stats      vus / ajoutes / sautes (mis à jour par référence)
 */
function collecteCatalogue(
    string $prefixe,
    int $profondeur,
    int $delayMs,
    bool $dryRun,
    array &$enumFaits,
    array &$stats,
    int &$erreurs,
    callable $log
): bool {
    if (isset($enumFaits['enum:' . $prefixe])) {
        $stats['sautes']++;
        return true;
    }

    [$status, $body] = httpGet(Site::urlCatalogue($prefixe), $delayMs);
    if ($status !== 200 || $body === null) {
        $log("  ! catalogue « {$prefixe} » : HTTP {$status}");
        $erreurs++;
        return false;   // pas de marqueur : sera repris au prochain lancement
    }

    $chants = Site::parseCatalogue($body);
    $stats['vus'] += count($chants);
    if (!$dryRun) {
        $stats['ajoutes'] += referenceChants($chants);
    }

    $complet = true;
    if ($profondeur < 4 && count($chants) >= Site::LIMITE_CATALOGUE) {
        $log(sprintf('  « %s » tronqué (%d) → redécoupage', $prefixe, count($chants)));
        foreach (Site::CARACTERES_PREFIXE as $ch) {
            $complet = collecteCatalogue(
                $prefixe . $ch, $profondeur + 1, $delayMs, $dryRun, $enumFaits, $stats, $erreurs, $log
            ) && $complet;
        }
    }

    if ($complet && !$dryRun) {
        Database::run(
            "INSERT INTO import_journal (source, ref, statut, traite_le)
             VALUES (?, ?, 'enumere', NOW())
             ON DUPLICATE KEY UPDATE traite_le = NOW()",
            [SOURCE, 'enum:' . $prefixe]
        );
        $enumFaits['enum:' . $prefixe] = true;
    }

    return $complet;
}

/**
 * Insère les chants trouvés dans import_journal (statut « a-importer »).
 * Purement additif : ON DUPLICATE KEY ne touche que l'url, jamais le statut ni
 * les données déjà récupérées. Renvoie le nombre de lignes réellement créées.
 *
 * @param array<int,array{id:int,slug:string,titre:string}> $chants
 */
function referenceChants(array $chants): int
{
    if ($chants === []) {
        return 0;
    }

    $avant = (int) Database::value('SELECT COUNT(*) FROM import_journal WHERE source = ?', [SOURCE]);

    foreach (array_chunk($chants, 200) as $lot) {
        $valeurs = [];
        $params  = [];
        foreach ($lot as $c) {
            $valeurs[] = '(?, ?, ?, ?)';
            array_push($params, SOURCE, (string) $c['id'], 'a-importer', Site::urlChant($c['id'], $c['slug']));
        }
        Database::run(
            'INSERT INTO import_journal (source, ref, statut, url) VALUES '
            . implode(', ', $valeurs)
            . ' ON DUPLICATE KEY UPDATE url = VALUES(url)',
            $params
        );
    }

    return (int) Database::value('SELECT COUNT(*) FROM import_journal WHERE source = ?', [SOURCE]) - $avant;
}

function majJournalStatut(int $ref, string $statut, bool $dryRun): void
{
    if ($dryRun) {
        return;
    }
    Database::run(
        "UPDATE import_journal SET statut = ?, traite_le = NOW() WHERE source = ? AND ref = ?",
        [$statut, SOURCE, (string) $ref]
    );
}

/** @param array<string,mixed> $donnees */
function majJournalDonnees(int $ref, array $donnees, bool $dryRun): void
{
    if ($dryRun) {
        return;
    }
    Database::update(
        'import_journal',
        ['statut' => 'avec-paroles', 'traite_le' => date('Y-m-d H:i:s')] + $donnees,
        ['source' => SOURCE, 'ref' => (string) $ref]
    );
}

/**
 * Développe « 0-9,A-Z,LE » en une liste de préfixes.
 *
 * @return list<string>
 */
function expandLettres(string $spec): array
{
    $out = [];
    foreach (explode(',', $spec) as $token) {
        $token = trim($token);
        if ($token === '') {
            continue;
        }
        if (preg_match('~^(\w)-(\w)$~', $token, $m) && ord($m[1]) <= ord($m[2])) {
            for ($c = ord($m[1]); $c <= ord($m[2]); $c++) {
                $out[] = strtoupper(chr($c));
            }
        } else {
            $out[] = strtoupper($token);
        }
    }

    return array_values(array_unique($out));
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
