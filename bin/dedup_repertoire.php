<?php

declare(strict_types=1);

/**
 * Dédoublonnage du répertoire (repertoire_chants).
 *
 * Filet de sécurité : le dédoublonnage se fait normalement à l'écriture
 * (App\Models\RepertoireChant::upsertDepuisImport/trouverDoublon, utilisées par
 * bin/import_repertoire.php et bin/import_url.php), mais ce script permet de
 * regrouper d'éventuels doublons résiduels.
 *
 * Regroupe les fiches par titre (hors caractères spéciaux — App\Models\Chant::reduire).
 * Au sein d'un groupe, deux fiches ne sont proposées à la fusion que si :
 *   - elles n'ont aucune source commune (jamais de fusion au sein d'une même
 *     source — deux fiches XYZ distinctes de chantonseneglise, par exemple,
 *     restent distinctes même si elles portent le même titre) ;
 *   - ET l'une au moins de ces conditions est vraie : un code commun (cote ou
 *     code IEV Emmanuel — App\Models\RepertoireChant::indiceCode), une mention
 *     « Emmanuel » dans les crédits de l'une des deux, ou les deux premières
 *     lignes des paroles identiques (App\Models\Chant::premieresLignes).
 * Garde la fiche « survivante » la plus complète (App\Models\RepertoireChant::mergerDans),
 * qui repointe au passage les chants de feuille et le journal d'import.
 *
 * Idempotent, relançable.
 *
 * Usage :
 *   php bin/dedup_repertoire.php --dry-run
 *   php bin/dedup_repertoire.php
 *
 * Options :
 *   --dry-run   Liste les fusions sans rien écrire.
 *   --quiet     N'affiche que le bilan.
 *   -h, --help  Cette aide.
 */

use App\Database;
use App\Import\Paroles;
use App\Models\Chant;
use App\Models\RepertoireChant;

if (PHP_SAPI !== 'cli') {
    exit("Ce script s'exécute en ligne de commande uniquement.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

$opts = getopt('h', ['dry-run', 'quiet', 'help']);
if (isset($opts['h']) || isset($opts['help'])) {
    $doc = (string) file_get_contents(__FILE__);
    echo substr($doc, (int) strpos($doc, '/**'), (int) strpos($doc, '*/') + 2 - (int) strpos($doc, '/**')), "\n";
    exit(0);
}
$dryRun = isset($opts['dry-run']);
$quiet  = isset($opts['quiet']);
$log = static function (string $msg) use ($quiet): void {
    if (!$quiet) {
        echo $msg, "\n";
    }
};

// --- Regroupement par titre ---------------------------------------------
$fiches = Database::all(
    'SELECT id, titre, chant, code, auteur, nb_couplets FROM repertoire_chants WHERE chant IS NOT NULL AND chant <> "" ORDER BY id'
);

$groupes = [];
foreach ($fiches as $f) {
    $groupes[Chant::reduire((string) $f['titre'])][] = $f;
}

$fusions = $supprimes = 0;

foreach ($groupes as $membres) {
    if (count($membres) < 2) {
        continue;
    }

    // Survivante : paroles les plus propres, puis le plus de couplets, puis les
    // paroles les plus longues, puis le plus petit id (stable).
    usort($membres, static function (array $a, array $b): int {
        return [Chant::scoreQualite((string) $a['chant']), -(int) $a['nb_couplets'], -mb_strlen((string) $a['chant']), (int) $a['id']]
            <=> [Chant::scoreQualite((string) $b['chant']), -(int) $b['nb_couplets'], -mb_strlen((string) $b['chant']), (int) $b['id']];
    });
    $survivante = array_shift($membres);

    $aFusionner = [];
    foreach ($membres as $m) {
        if (sourceCommune((int) $survivante['id'], (int) $m['id'])) {
            continue; // jamais de fusion au sein d'une même source
        }
        if (!doublonProbable($survivante, $m)) {
            continue;
        }
        $aFusionner[] = $m;
    }

    if ($aFusionner === []) {
        continue;
    }

    $log(sprintf('• %s', preg_replace('~\s+~', ' ', (string) $survivante['titre'])));
    $log(sprintf(
        '    garde #%d (%d couplets) ← fusionne %s',
        $survivante['id'],
        (int) $survivante['nb_couplets'],
        implode(', ', array_map(static fn ($p) => '#' . $p['id'], $aFusionner))
    ));

    if (!$dryRun) {
        foreach ($aFusionner as $m) {
            RepertoireChant::mergerDans((int) $survivante['id'], (int) $m['id']);
        }
    }

    $fusions++;
    $supprimes += count($aFusionner);
}

echo sprintf(
    "\n%d groupe(s) fusionné(s), %d fiche(s) supprimée(s)%s.\n",
    $fusions,
    $supprimes,
    $dryRun ? ' (dry-run)' : ''
);

exit(0);

// =======================================================================
// Fonctions
// =======================================================================

/** $a et $b partagent-elles au moins une source (import_journal) ? */
function sourceCommune(int $a, int $b): bool
{
    $sourcesA = array_column(Database::all('SELECT DISTINCT source FROM import_journal WHERE chant_id = ?', [$a]), 'source');
    $sourcesB = array_column(Database::all('SELECT DISTINCT source FROM import_journal WHERE chant_id = ?', [$b]), 'source');

    return array_intersect($sourcesA, $sourcesB) !== [];
}

/**
 * Deux fiches de même titre sont-elles vraisemblablement le même chant ?
 * Code commun, mention « Emmanuel » dans les crédits de l'une des deux, ou
 * deux premières lignes des paroles identiques.
 *
 * @param array<string,mixed> $a
 * @param array<string,mixed> $b
 */
function doublonProbable(array $a, array $b): bool
{
    if (RepertoireChant::indiceCode(['code' => $a['code']], ['code' => $b['code']])) {
        return true;
    }
    foreach ([$a['auteur'], $b['auteur']] as $auteur) {
        if ((string) $auteur !== '' && stripos(Paroles::sansAccents((string) $auteur), 'emmanuel') !== false) {
            return true;
        }
    }
    $premieres = Chant::premieresLignes((string) $a['chant']);

    return $premieres !== '' && $premieres === Chant::premieresLignes((string) $b['chant']);
}
