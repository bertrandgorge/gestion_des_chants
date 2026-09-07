<?php

declare(strict_types=1);

/**
 * Passe « IMPORT » commune à tous les sites : transforme les lignes
 * « avec-paroles » d'« import_journal » (déposées par les passes ENUM+FETCH de
 * bin/import_chantonseneglise.php, bin/import_catechisme_emmanuel.php et
 * bin/import_chorale_pole_fontainebleau.php) en fiches du répertoire partagé
 * (App\Models\RepertoireChant), en les dédoublonnant.
 *
 * Dédoublonnage : jamais entre deux lignes d'une même source. Entre sources
 * différentes, sur le titre (hors caractères spéciaux) ; en cas d'ambiguïté
 * (plusieurs correspondances de même titre), on départage par un indice de
 * correspondance (cote / code IEV Emmanuel / mention « Emmanuel » dans les
 * crédits), puis par les deux premières lignes des paroles. Voir
 * App\Models\RepertoireChant pour le détail des règles.
 *
 * Deux modes :
 *
 *   - Par défaut : incrémental, ne traite que les lignes « avec-paroles » pas
 *     encore importées, en les comparant au répertoire déjà constitué
 *     (App\Models\RepertoireChant::upsertDepuisImport). Relançable sans risque.
 *
 *   - --reset : reconstruit le répertoire ENTIER à partir de zéro, en suivant
 *     précisément l'algorithme en deux passes voulu :
 *       1. Depuis la source catechisme-emmanuel (« Emmanuel »), recherche d'un
 *          doublon par titre dans les DEUX autres sources combinées ; en cas
 *          d'ambiguïté, départage par code IEV / mention « Emmanuel ».
 *       2. Depuis la source chorale-pole-fontainebleau, pour les chants pas
 *          encore rapprochés de chantonseneglise, recherche d'un doublon par
 *          titre dans SEULEMENT chantonseneglise ; en cas d'ambiguïté,
 *          départage par cote.
 *     Dans les deux passes, à défaut de désambiguïsation par code/auteur, on
 *     compare les deux premières lignes des paroles.
 *     ATTENTION : --reset vide et reconstruit « repertoire_chants » avec de
 *     nouveaux id : toute correction manuelle faite depuis l'interface
 *     (titre, codes, mots-clés, fusions, séparations…) est perdue, et les
 *     chants de feuille qui pointaient vers une fiche du répertoire perdent ce
 *     lien (leur propre contenu n'est pas touché). À réserver à une remise à
 *     plat volontaire — faites une sauvegarde de la base avant.
 *
 * Usage :
 *   php bin/import_repertoire.php
 *   php bin/import_repertoire.php --source=catechisme-emmanuel
 *   php bin/import_repertoire.php --reset --dry-run
 *
 * Options :
 *   --source=NOM  Mode par défaut seulement : ne traiter qu'une source
 *                 (chantonseneglise, catechisme-emmanuel, chorale-pole-fontainebleau).
 *   --reset       Reconstruit tout le répertoire depuis zéro (voir ci-dessus).
 *   --dry-run     N'écrit rien en base.
 *   --quiet       Affiche moins de messages.
 *   -h, --help    Cette aide.
 */

use App\Database;
use App\Import\Paroles;
use App\Import\TypeLiturgique;
use App\Models\Chant;
use App\Models\RepertoireChant;

const SOURCE_EMMANUEL = 'catechisme-emmanuel';
const SOURCE_FONTAINEBLEAU = 'chorale-pole-fontainebleau';
const SOURCE_CHANTONSENEGLISE = 'chantonseneglise';

if (PHP_SAPI !== 'cli') {
    exit("Ce script s'exécute en ligne de commande uniquement.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

$opts = getopt('h', ['source:', 'reset', 'dry-run', 'quiet', 'help']);
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

if (isset($opts['reset'])) {
    reconstruire($dryRun, $log);
    exit(0);
}

importerIncremental(isset($opts['source']) ? (string) $opts['source'] : null, $dryRun, $log);
exit(0);

// =======================================================================
// Mode par défaut : incrémental
// =======================================================================

function importerIncremental(?string $source, bool $dryRun, callable $log): void
{
    $sql = "SELECT source, ref, titre, code, code_repertoire, auteur, type, nom, categorie, chant, chant_id
              FROM import_journal
             WHERE statut = 'avec-paroles'";
    $params = [];
    if ($source !== null) {
        $sql .= ' AND source = ?';
        $params[] = $source;
    }
    $sql .= ' ORDER BY source, ref';

    $aTraiter = Database::all($sql, $params);
    $log(sprintf('%d fiche(s) à importer.', count($aTraiter)));

    $crees = $completes = 0;
    $parSource = [];

    foreach ($aTraiter as $j) {
        $src = (string) $j['source'];
        $parSource[$src] ??= 0;
        $parSource[$src]++;

        $chant = Paroles::format((string) $j['chant']);
        $ligne = ligneDepuisJournal($j, $chant);
        $motCle = motCleDepuisJournal($src, $j);
        $chantIdActuel = $j['chant_id'] !== null ? (int) $j['chant_id'] : null;

        $existaitDeja = $chantIdActuel !== null || RepertoireChant::trouverDoublon(
            $ligne['titre'], $chant, $ligne['code'], $ligne['code_repertoire'], $src
        ) !== null;
        $existaitDeja ? $completes++ : $crees++;

        if ($dryRun) {
            continue;
        }

        $repertoireId = RepertoireChant::upsertDepuisImport($src, $ligne, $chantIdActuel, $motCle);
        Database::run(
            "UPDATE import_journal
                SET statut = 'importe', chant_id = ?, titre_reduit = ?, empreinte_paroles = ?, traite_le = NOW()
              WHERE source = ? AND ref = ?",
            [$repertoireId, Chant::reduire($ligne['titre']), Chant::premieresLignes($chant), $src, (string) $j['ref']]
        );
    }

    $log('');
    foreach ($parSource as $src => $n) {
        $log(sprintf('  %-24s : %d', $src, $n));
    }
    echo sprintf("\n%d fiche(s) créée(s), %d complétée(s)%s.\n", $crees, $completes, $dryRun ? ' (dry-run)' : '');
}

// =======================================================================
// --reset : reconstruction complète en deux passes
// =======================================================================

function reconstruire(bool $dryRun, callable $log): void
{
    if (!$dryRun) {
        Database::run("UPDATE import_journal SET statut = 'avec-paroles', chant_id = NULL WHERE statut IN ('importe', 'complete')");
        // ON DELETE SET NULL sur chants.repertoire_id : les chants de feuille
        // gardent leur contenu, perdent seulement le lien vers le répertoire.
        Database::run('DELETE FROM repertoire_chants');
        $log("Répertoire vidé, import_journal remis en « avec-paroles ».\n");
    } else {
        $log("--dry-run : le répertoire actuel et import_journal ne sont pas touchés (la simulation part donc de l'état déjà importé).\n");
    }

    // En dry-run, import_journal n'est pas remis en « avec-paroles » : on
    // inclut donc aussi les statuts déjà importés pour simuler sur les mêmes
    // lignes que la reconstruction réelle traiterait.
    $statuts = $dryRun ? "'avec-paroles', 'importe', 'complete'" : "'avec-paroles'";

    // Les paroles sont mises en forme une seule fois ici (App\Import\Paroles::format
    // est un peu coûteux et sera relu par chaque désambiguïsation ci-dessous).
    $parSource = [SOURCE_EMMANUEL => [], SOURCE_FONTAINEBLEAU => [], SOURCE_CHANTONSENEGLISE => []];
    foreach (Database::all(
        "SELECT source, ref, titre, code, code_repertoire, auteur, type, nom, categorie, chant
           FROM import_journal WHERE statut IN ($statuts) ORDER BY source, ref"
    ) as $row) {
        $src = (string) $row['source'];
        if (isset($parSource[$src])) {
            $row['chant'] = Paroles::format((string) $row['chant']);
            $parSource[$src][$row['ref']] = $row;
        }
    }
    $log(sprintf(
        '%d chants Emmanuel, %d Fontainebleau, %d Chantons en Église.',
        count($parSource[SOURCE_EMMANUEL]), count($parSource[SOURCE_FONTAINEBLEAU]), count($parSource[SOURCE_CHANTONSENEGLISE])
    ));

    // "source|ref" => fiche répertoire (id réel, ou clé fictive en dry-run).
    $resolu = [];
    $prochainIdFictif = -1;

    $creerOuCompleter = static function (string $source, array $row, ?int $cible) use ($dryRun, &$prochainIdFictif): int {
        // $row['chant'] est déjà mis en forme (voir le chargement de $parSource ci-dessus).
        $ligne = ligneDepuisJournal($row, (string) $row['chant']);
        $motCle = motCleDepuisJournal($source, $row);

        if ($dryRun) {
            return $cible ?? $prochainIdFictif--;
        }

        // $cible connu (fusion décidée par l'algorithme en deux passes ci-dessous)
        // → on complète directement, sans repasser par une recherche de doublon.
        // $cible === null (aucune correspondance trouvée) → création directe,
        // sans recherche non plus (RepertoireChant::upsertDepuisImport chercherait
        // dans tout le répertoire déjà constitué à chaque appel : trop coûteux
        // sur plusieurs milliers de fiches).
        $id = $cible !== null
            ? RepertoireChant::upsertDepuisImport($source, $ligne, $cible, $motCle)
            : RepertoireChant::creerDepuisImport($ligne, $motCle);
        Database::run(
            "UPDATE import_journal
                SET statut = 'importe', chant_id = ?, titre_reduit = ?, empreinte_paroles = ?, traite_le = NOW()
              WHERE source = ? AND ref = ?",
            [$id, Chant::reduire($ligne['titre']), Chant::premieresLignes($ligne['chant']), $source, (string) $row['ref']]
        );

        return $id;
    };

    // --- Passe 1 : Emmanuel -> {Fontainebleau, Chantons en Église} --------
    $log("\n=== Passe 1 : Emmanuel ===");
    $pool = [];
    foreach ([SOURCE_FONTAINEBLEAU, SOURCE_CHANTONSENEGLISE] as $src) {
        foreach ($parSource[$src] as $ref => $row) {
            $pool[$src . '|' . $ref] = $row;
        }
    }

    $fusionsP1 = $creesP1 = 0;
    foreach ($parSource[SOURCE_EMMANUEL] as $ref => $e) {
        $source = ['titre' => (string) $e['titre'], 'chant' => (string) $e['chant'], 'code_repertoire' => $e['code_repertoire']];

        $candidats = [];
        foreach ($pool as $cle => $r) {
            $candidats[] = [
                'cle' => $cle, 'titre' => (string) $r['titre'], 'chant' => (string) $r['chant'],
                'code' => (string) $r['code'], 'auteur' => (string) $r['auteur'],
            ];
        }

        $cleMatch = RepertoireChant::meilleurCandidat(
            $source, $candidats, static fn (array $s, array $c) => RepertoireChant::indiceEmmanuel($s, $c)
        );

        $id = $creerOuCompleter(SOURCE_EMMANUEL, $e, null);
        $resolu[SOURCE_EMMANUEL . '|' . $ref] = $id;

        if ($cleMatch !== null) {
            $m = $pool[$cleMatch];
            unset($pool[$cleMatch]);
            $srcM = explode('|', $cleMatch, 2)[0];
            $idM = $creerOuCompleter($srcM, $m, $id);
            $resolu[$cleMatch] = $idM;
            $fusionsP1++;
        } else {
            $creesP1++;
        }
    }
    $log(sprintf('  %d fusion(s), %d fiche(s) Emmanuel sans correspondance.', $fusionsP1, $creesP1));

    // --- Passe 2 : Fontainebleau (pas encore lié à chantonseneglise) -> chantonseneglise ---
    $log("\n=== Passe 2 : Fontainebleau -> Chantons en Église ===");
    $poolCse = [];
    foreach ($parSource[SOURCE_CHANTONSENEGLISE] as $ref => $row) {
        $cle = SOURCE_CHANTONSENEGLISE . '|' . $ref;
        if (!isset($resolu[$cle])) { // pas déjà consommé par la passe 1
            $poolCse[$cle] = $row;
        }
    }

    $fusionsP2 = $creesP2 = $dejaLiesP2 = 0;
    foreach ($parSource[SOURCE_FONTAINEBLEAU] as $ref => $f) {
        $cleF   = SOURCE_FONTAINEBLEAU . '|' . $ref;
        $cibleF = $resolu[$cleF] ?? null;

        // Déjà rapproché d'une fiche chantonseneglise en passe 1 ? (fiche
        // fusionnée avec un chant Emmanuel qui avait lui-même trouvé sa
        // correspondance dans chantonseneglise plutôt que fontainebleau.)
        // Non vérifiable en dry-run (import_journal n'est pas mis à jour) :
        // on retente alors le rapprochement, sans conséquence puisque rien n'est écrit.
        if ($cibleF !== null && !$dryRun && RepertoireChant::aSource($cibleF, SOURCE_CHANTONSENEGLISE)) {
            $dejaLiesP2++;
            continue;
        }

        $source = ['titre' => (string) $f['titre'], 'chant' => (string) $f['chant'], 'code' => (string) $f['code']];

        $candidats = [];
        foreach ($poolCse as $cle => $r) {
            $candidats[] = [
                'cle' => $cle, 'titre' => (string) $r['titre'], 'chant' => (string) $r['chant'],
                'code' => (string) $r['code'], 'auteur' => (string) $r['auteur'],
            ];
        }

        $cleMatch = RepertoireChant::meilleurCandidat(
            $source, $candidats, static fn (array $s, array $c) => RepertoireChant::indiceCode($s, $c)
        );

        $id = $creerOuCompleter(SOURCE_FONTAINEBLEAU, $f, $cibleF);
        $resolu[$cleF] = $id;

        if ($cleMatch !== null) {
            $m = $poolCse[$cleMatch];
            unset($poolCse[$cleMatch]);
            $idM = $creerOuCompleter(SOURCE_CHANTONSENEGLISE, $m, $id);
            $resolu[$cleMatch] = $idM;
            $fusionsP2++;
        } elseif ($cibleF === null) {
            $creesP2++;
        }
    }
    $log(sprintf(
        '  %d fusion(s), %d déjà liées, %d fiche(s) Fontainebleau sans nouvelle correspondance.',
        $fusionsP2, $dejaLiesP2, $creesP2
    ));

    // --- Chantons en Église restant, et Fontainebleau non encore traité ---
    $log("\n=== Fiches restantes (créées telles quelles) ===");
    $restantes = 0;
    foreach ($parSource[SOURCE_CHANTONSENEGLISE] as $ref => $row) {
        $cle = SOURCE_CHANTONSENEGLISE . '|' . $ref;
        if (!isset($resolu[$cle])) {
            $resolu[$cle] = $creerOuCompleter(SOURCE_CHANTONSENEGLISE, $row, null);
            $restantes++;
        }
    }
    $log(sprintf('  %d fiche(s) chantonseneglise restante(s).', $restantes));

    $total = count($resolu);
    echo sprintf(
        "\nTotal : %d ligne(s) traitée(s) → %d fiche(s) du répertoire%s.\n",
        $total,
        count(array_unique($resolu)),
        $dryRun ? ' (dry-run, aucune écriture)' : ''
    );
}

// =======================================================================
// Fonctions communes
// =======================================================================

/** @param array<string,mixed> $j */
function ligneDepuisJournal(array $j, string $chantFormatte): array
{
    return [
        'titre'           => (string) $j['titre'],
        'code'            => $j['code'],
        'code_repertoire' => $j['code_repertoire'],
        'auteur'          => $j['auteur'],
        'type'            => (string) ($j['type'] ?: TypeLiturgique::DEFAUT),
        'nom'             => (string) ($j['nom'] ?: 'Chant'),
        'chant'           => $chantFormatte,
        'nb_couplets'     => count_couplets($chantFormatte, false),
    ];
}

/** @param array<string,mixed> $j */
function motCleDepuisJournal(string $source, array $j): ?string
{
    // La catégorie brute de chantonseneglise n'est pas fiable comme mot-clé.
    return $source !== SOURCE_CHANTONSENEGLISE && (string) ($j['categorie'] ?? '') !== ''
        ? (string) $j['categorie']
        : null;
}
