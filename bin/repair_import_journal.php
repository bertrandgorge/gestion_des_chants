<?php

declare(strict_types=1);

/**
 * Répare les liens import_journal.chant_id ↔ chants.
 *
 * Si la table « chants » est rechargée depuis une sauvegarde alors que
 * « import_journal » ne l'est pas (ou l'inverse), les chant_id notés dans le
 * journal ne désignent plus le bon chant — voire un chant sans rapport, si
 * l'auto-increment a réattribué l'identifiant à un import ultérieur.
 *
 * Pour chaque ligne « importe » / « complete », ce script :
 *   1. garde le lien s'il est correct (le chant pointé a la même clé de
 *      dédoublonnage — titre + 1re ligne du refrain — que la ligne de journal) ;
 *   2. sinon, relie à la fiche de catalogue de même url, puis de même clé ;
 *   3. sinon (le chant a disparu), recrée la fiche depuis les données conservées
 *      dans le journal — sans aucune requête réseau.
 *
 * Les données parsées (titre, code, auteur, type, nom, paroles) restant toujours
 * stockées dans import_journal, aucune fiche n'est perdue.
 *
 * Idempotent. Ne touche jamais aux chants d'une feuille de messe.
 *
 * Usage :
 *   php bin/repair_import_journal.php --dry-run
 *   php bin/repair_import_journal.php
 *
 * Options :
 *   --dry-run   Diagnostic sans écriture.
 *   --quiet     N'affiche que le bilan.
 *   -h, --help  Cette aide.
 */

use App\Database;
use App\Import\Paroles;
use App\Import\TypeLiturgique;
use App\Models\Chant;

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

// --- Index des fiches de catalogue existantes -------------------------
$parUrl = [];
$parCle = [];
foreach (Database::all(
    'SELECT id, titre, chant, url FROM chants WHERE feuille_id IS NULL AND url IS NOT NULL'
) as $c) {
    $parUrl[(string) $c['url']] ??= (int) $c['id'];
    $parCle[Chant::cleDedup((string) $c['titre'], (string) $c['chant'])] ??= (int) $c['id'];
}

$corrects = $relies = $recrees = 0;
$parSource = [];

foreach (Database::all(
    "SELECT source, ref, url, titre, code, auteur, type, nom, chant, chant_id
       FROM import_journal
      WHERE statut IN ('importe', 'complete')
      ORDER BY source, ref"
) as $j) {
    $source = (string) $j['source'];
    $parSource[$source] ??= ['ok' => 0, 'relie' => 0, 'recree' => 0];

    $cle    = Chant::cleDedup((string) $j['titre'], (string) $j['chant']);
    $actuel = $j['chant_id'] !== null
        ? Database::one('SELECT titre, chant FROM chants WHERE id = ?', [(int) $j['chant_id']])
        : null;

    if ($actuel !== null
        && Chant::cleDedup((string) $actuel['titre'], (string) $actuel['chant']) === $cle
    ) {
        $corrects++;
        $parSource[$source]['ok']++;
        continue;
    }

    $cible = $parUrl[(string) $j['url']] ?? $parCle[$cle] ?? null;

    if ($cible !== null) {
        $relies++;
        $parSource[$source]['relie']++;
        $log(sprintf('  ~ %-22s %-40s #%s → #%d', $source, tronque((string) $j['titre'], 40),
            $j['chant_id'] ?? '?', $cible));
        if (!$dryRun) {
            Database::run(
                'UPDATE import_journal SET chant_id = ? WHERE source = ? AND ref = ?',
                [$cible, $source, (string) $j['ref']]
            );
        }
        continue;
    }

    // Le chant a disparu : on le recrée depuis les données du journal.
    $chant    = Paroles::format((string) $j['chant']);
    $couplets = count_couplets($chant, false);
    $ligne = [
        'feuille_id'  => null,
        'nom'         => (string) ($j['nom'] ?: 'Chant'),
        'type'        => (string) ($j['type'] ?: TypeLiturgique::DEFAUT),
        'position'    => 0,
        'titre'       => $j['titre'],
        'code'        => $j['code'],
        'auteur'      => $j['auteur'],
        'chant'       => $chant,
        'nb_couplets' => $couplets,
        'url'         => $j['url'],
    ];

    $recrees++;
    $parSource[$source]['recree']++;
    $log(sprintf('  + %-22s %-40s (recréée)', $source, tronque((string) $j['titre'], 40)));

    if (!$dryRun) {
        $id = Database::insert('chants', $ligne);
        Database::run(
            'UPDATE import_journal SET chant_id = ? WHERE source = ? AND ref = ?',
            [$id, $source, (string) $j['ref']]
        );
        $parUrl[(string) $j['url']] ??= $id;
        $parCle[$cle] ??= $id;
    }
}

// --- Bilan ------------------------------------------------------------
$log('');
foreach ($parSource as $source => $s) {
    $log(sprintf(
        '  %-24s : %d ok, %d reliées, %d recréées',
        $source, $s['ok'], $s['relie'], $s['recree']
    ));
}
echo sprintf(
    "\n%d lien(s) correct(s), %d relié(s), %d fiche(s) recréée(s)%s.\n",
    $corrects, $relies, $recrees, $dryRun ? ' (dry-run)' : ''
);

exit(0);

/** Tronque une chaîne à $max caractères. */
function tronque(string $s, int $max): string
{
    return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max)) . '…' : $s;
}
