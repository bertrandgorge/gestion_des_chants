<?php

declare(strict_types=1);

/**
 * Import des ordinaires de messe du site de la Communauté de l'Emmanuel
 * (https://emmanuel.info/ordinaires-de-messe/) — à ne pas confondre avec
 * bin/import_catechisme_emmanuel.php (catechisme-emmanuel.com).
 *
 * Une fiche d'ordinaire (« Messe de Saint Jean »…) a une URL unique et
 * regroupe plusieurs chants qui partagent la même musique (Kyrie, Gloria,
 * Alléluia, Sanctus, Anamnèse, Agnus…) : contrairement aux autres imports, une
 * page source produit donc plusieurs fiches du répertoire, toutes marquées du
 * même `ordinaire` (App\Models\RepertoireChant::pourOrdinaire, utilisé par la
 * reprise groupée d'une feuille de chant — App\Controllers\ChantController::reprendreOrdinaire).
 *
 * Une seule passe (pas d'ENUM/FETCH séparés comme les autres imports : la liste
 * ne compte qu'une quinzaine de fiches, inutile de la mémoriser à part) qui
 * écrit directement dans le répertoire, comme App\Import\UrlImporter — le
 * dédoublonnage habituel (App\Models\RepertoireChant::upsertDepuisImport) n'est
 * de toute façon pas adapté à une ligne d'import_journal par page source.
 *
 * Usage :
 *   php bin/import_emmanuel_ordinaires.php
 *   php bin/import_emmanuel_ordinaires.php --dry-run
 *
 * Options :
 *   --delay=MS   Pause minimale entre requêtes HTTP en ms (défaut : 1000).
 *   --refresh    Relance même les ordinaires déjà importés (par défaut, ne
 *                retélécharge que les fiches jamais vues ou en erreur).
 *   --dry-run    N'écrit rien en base.
 *   --quiet      Affiche moins de messages.
 *   -h, --help   Cette aide.
 */

use App\Database;
use App\Import\EmmanuelOrdinaires as Site;
use App\Import\Paroles;
use App\Models\Chant;
use App\Models\RepertoireChant;

const SOURCE = 'emmanuel-ordinaires';

if (PHP_SAPI !== 'cli') {
    exit("Ce script s'exécute en ligne de commande uniquement.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

$opts = getopt('h', ['delay:', 'refresh', 'dry-run', 'quiet', 'help']);
if (isset($opts['h']) || isset($opts['help'])) {
    $doc = (string) file_get_contents(__FILE__);
    echo substr($doc, (int) strpos($doc, '/**'), (int) strpos($doc, '*/') + 2 - (int) strpos($doc, '/**')), "\n";
    exit(0);
}

$delayMs = isset($opts['delay']) ? max(0, (int) $opts['delay']) : 1000;
$refresh = isset($opts['refresh']);
$dryRun  = isset($opts['dry-run']);
$quiet   = isset($opts['quiet']);

$log = static function (string $msg) use ($quiet): void {
    if (!$quiet) {
        echo $msg, "\n";
    }
};

[$status, $body] = httpGet(Site::urlListe(), $delayMs);
if ($status !== 200 || $body === null) {
    exit('  ! ' . Site::urlListe() . " : HTTP {$status}\n");
}

$ordinaires = Site::parseListe($body);
$log(sprintf('%d ordinaire(s) trouvé(s) sur %s.', count($ordinaires), Site::urlListe()));

$dejaTraites = [];
if (!$refresh) {
    foreach (Database::all("SELECT DISTINCT SUBSTRING_INDEX(ref, ':', 1) AS slug FROM import_journal WHERE source = ?", [SOURCE]) as $row) {
        $dejaTraites[(string) $row['slug']] = true;
    }
}

$fichesCreees = $fichesCompletees = $ordinairesImportes = $erreurs = 0;

foreach ($ordinaires as $slug => $url) {
    if (isset($dejaTraites[$slug])) {
        continue;
    }

    [$status, $body] = httpGet($url, $delayMs);
    if ($status !== 200 || $body === null) {
        $erreurs++;
        $log("  ! {$slug} : HTTP {$status}");
        continue;
    }

    $data = Site::parseOrdinaire($body);
    if ($data === null) {
        $erreurs++;
        $log("  ! {$slug} : aucune parole exploitable");
        continue;
    }

    $ordinairesImportes++;
    $log(sprintf('  %s : %d partie(s) — %s', $slug, count($data['chants']), $data['nom']));

    // Le nom lu sur le site inclut parfois déjà « Messe » (« Messe de l'Abbaye »)
    // : ne pas le préfixer une seconde fois.
    $nomMesse = preg_match('~^messe\b~iu', $data['nom']) ? $data['nom'] : 'Messe ' . $data['nom'];

    foreach ($data['chants'] as $partie) {
        $chantFormatte = Paroles::format($partie['chant']);
        $ref = $slug . ':' . $partie['type'];

        $ligne = [
            'titre'       => tronque($partie['nom'] . ' — ' . $nomMesse, 255),
            'code'        => null,
            'auteur'      => $data['auteur'] !== '' ? tronque($data['auteur'], 190) : null,
            'type'        => $partie['type'],
            'nom'         => $partie['nom'],
            'ordinaire'   => tronque($data['nom'], 190),
            'chant'       => $chantFormatte,
            'nb_couplets' => count_couplets($chantFormatte, false),
        ];

        if ($dryRun) {
            echo sprintf(
                "    · %-10s %s (%s)%s\n",
                $partie['type'],
                $ligne['titre'],
                $ligne['ordinaire'],
                $ligne['auteur'] !== null ? ' — ' . $ligne['auteur'] : ''
            );
            continue;
        }

        $existant = Database::one('SELECT chant_id FROM import_journal WHERE source = ? AND ref = ?', [SOURCE, $ref]);
        $dejaLie = $existant !== null && $existant['chant_id'] !== null ? (int) $existant['chant_id'] : null;

        $dedupAvant = $dejaLie ?? RepertoireChant::trouverDoublon(
            $ligne['titre'], $ligne['chant'], null, null, SOURCE
        );
        $repertoireId = RepertoireChant::upsertDepuisImport(SOURCE, $ligne, $dejaLie, null);
        $dedupAvant !== null ? $fichesCompletees++ : $fichesCreees++;

        Database::run(
            "INSERT INTO import_journal
                (source, ref, statut, url, titre, titre_reduit, auteur, type, nom, ordinaire, chant, empreinte_paroles, chant_id, traite_le)
             VALUES (?, ?, 'importe', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                statut = 'importe', url = VALUES(url), titre = VALUES(titre), titre_reduit = VALUES(titre_reduit),
                auteur = VALUES(auteur), type = VALUES(type), nom = VALUES(nom), ordinaire = VALUES(ordinaire),
                chant = VALUES(chant), empreinte_paroles = VALUES(empreinte_paroles), chant_id = VALUES(chant_id), traite_le = NOW()",
            [
                SOURCE,
                tronque($ref, 190),
                $url,
                $ligne['titre'],
                Chant::reduire($ligne['titre']),
                $ligne['auteur'],
                $ligne['type'],
                $ligne['nom'],
                $ligne['ordinaire'],
                $partie['chant'],
                Chant::premieresLignes($chantFormatte),
                $repertoireId,
            ]
        );
    }
}

echo sprintf(
    "\n%d ordinaire(s) importé(s) (%d erreur(s)) : %d fiche(s) créée(s), %d complétée(s)%s.\n",
    $ordinairesImportes,
    $erreurs,
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
