<?php

declare(strict_types=1);

/**
 * Dédoublonnage des fiches de catalogue (feuille_id = NULL, url renseignée).
 *
 * Un même chant importé de plusieurs sources (chantonseneglise.fr,
 * catechisme-emmanuel.com, choralepolefontainebleau.org) crée aujourd'hui une
 * fiche par source. Ce script les regroupe par clé de dédoublonnage
 * (App\Models\Chant::cleDedup : titre + première ligne du refrain, réduits à
 * leurs caractères significatifs), garde une seule fiche « survivante »
 * complétée avec le meilleur de chaque source, repointe import_journal.chant_id
 * vers elle et supprime les autres.
 *
 * Idempotent, relançable. Ne touche jamais aux chants d'une feuille de messe.
 *
 * Usage :
 *   php bin/dedup_chants.php --dry-run
 *   php bin/dedup_chants.php
 *
 * Options :
 *   --dry-run   Liste les fusions sans rien écrire.
 *   --quiet     N'affiche que le bilan.
 *   -h, --help  Cette aide.
 */

use App\Database;
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

// --- Regroupement par clé de dédoublonnage ----------------------------
$fiches = Database::all(
    'SELECT id, titre, code, auteur, type, nom, chant, nb_couplets, url
       FROM chants
      WHERE feuille_id IS NULL AND url IS NOT NULL AND chant IS NOT NULL AND chant <> ""
      ORDER BY id'
);

$groupes = [];
foreach ($fiches as $f) {
    $groupes[Chant::cleDedup((string) $f['titre'], (string) $f['chant'])][] = $f;
}

$fusions = $supprimes = 0;

foreach ($groupes as $cle => $membres) {
    if (count($membres) < 2) {
        continue;
    }

    // Survivante : paroles les plus propres (moins de lignes de crédits), puis
    // le plus de couplets, puis les paroles les plus longues, puis le plus petit
    // id (stable).
    usort($membres, static function (array $a, array $b): int {
        return [Chant::scoreQualite((string) $a['chant']), -(int) $a['nb_couplets'], -mb_strlen((string) $a['chant']), (int) $a['id']]
            <=> [Chant::scoreQualite((string) $b['chant']), -(int) $b['nb_couplets'], -mb_strlen((string) $b['chant']), (int) $b['id']];
    });
    $survivante = $membres[0];
    $perdants   = array_slice($membres, 1);

    // Complète la survivante avec ce que les perdants ont de mieux : cote /
    // auteur manquants, vrai type liturgique si elle était restée au défaut.
    $code   = trim((string) $survivante['code']);
    $auteur = trim((string) $survivante['auteur']);
    $type   = (string) $survivante['type'];
    $nom    = (string) $survivante['nom'];
    foreach ($perdants as $m) {
        if ($code === '' && trim((string) $m['code']) !== '') {
            $code = trim((string) $m['code']);
        }
        if ($auteur === '' && trim((string) $m['auteur']) !== '') {
            $auteur = trim((string) $m['auteur']);
        }
        if (($type === '' || $type === TypeLiturgique::DEFAUT)
            && (string) $m['type'] !== '' && (string) $m['type'] !== TypeLiturgique::DEFAUT
        ) {
            $type = (string) $m['type'];
            $nom  = (string) $m['nom'];
        }
    }
    $maj = [];
    if ($code !== trim((string) $survivante['code'])) {
        $maj['code'] = $code;
    }
    if ($auteur !== trim((string) $survivante['auteur'])) {
        $maj['auteur'] = $auteur;
    }
    if ($type !== (string) $survivante['type']) {
        $maj['type'] = $type;
        $maj['nom']  = $nom;
    }

    $log(sprintf(
        '• %s',
        preg_replace('~\s+~', ' ', (string) $survivante['titre'])
    ));
    $log(sprintf(
        '    garde #%d (%d couplets) ← fusionne %s',
        $survivante['id'],
        (int) $survivante['nb_couplets'],
        implode(', ', array_map(static fn ($p) => '#' . $p['id'], $perdants))
    ));

    if (!$dryRun) {
        if ($maj !== []) {
            Database::update('chants', $maj, ['id' => (int) $survivante['id']]);
        }
        foreach ($perdants as $p) {
            Database::run(
                'UPDATE import_journal SET chant_id = ? WHERE chant_id = ?',
                [(int) $survivante['id'], (int) $p['id']]
            );
            Database::delete('chants', ['id' => (int) $p['id']]);
        }
    }

    $fusions++;
    $supprimes += count($perdants);
}

echo sprintf(
    "\n%d groupe(s) fusionné(s), %d fiche(s) supprimée(s)%s.\n",
    $fusions,
    $supprimes,
    $dryRun ? ' (dry-run)' : ''
);

exit(0);
