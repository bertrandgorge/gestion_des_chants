<?php

declare(strict_types=1);

/**
 * Import ponctuel d'un seul chant, à partir de son URL.
 *
 * Enveloppe CLI de App\Import\UrlImporter (voir cette classe pour le détail :
 * sites reconnus, dédoublonnage). Utilisée aussi, en direct, par l'interface
 * web (Répertoire → « Importer depuis une URL »).
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

use App\Import\UrlImporter;

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

$resultat = UrlImporter::importer($url, $dryRun);
echo $resultat['message'], "\n";
exit($resultat['ok'] ? 0 : 1);
