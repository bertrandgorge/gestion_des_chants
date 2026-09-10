<?php

declare(strict_types=1);

/**
 * Reclasse les fiches du répertoire (repertoire_chants) d'après leur cote SECLI
 * (issue #11).
 *
 * Le type liturgique d'une fiche était jusqu'ici déduit d'un libellé de
 * catégorie en texte libre, peu fiable : la grande majorité des fiches sont
 * restées au type par défaut « entree ». Or beaucoup portent une cote SECLI
 * (« D 68-39 », « ZL 24 », « T282 »…) dont la lettre de rite donne de façon
 * sûre la fonction liturgique : A=entrée, B=offertoire, D=communion, T=envoi,
 * Z=psaume, U=acclamation (alléluia). La lettre de temps (« GA… » = Carême)
 * alimente en plus les mots-clés.
 *
 * Voir App\Import\Secli.
 *
 * Non destructif : ne modifie que `type`, `nom` (si resté au libellé par défaut)
 * et `mots_cles` (union, jamais de retrait). Ne touche jamais aux id, aux
 * paroles, aux liens de feuille. Idempotent, relançable.
 *
 * Par défaut (mode agressif) : écrase aussi un type déjà attribué quand la cote
 * SECLI sûre le contredit. Restent protégées, sauf --force :
 *   - les fiches d'un ordinaire de messe curé (`ordinaire` renseigné,
 *     cf. bin/import_emmanuel_ordinaires.php) ;
 *   - les fiches déjà typées en partie d'ordinaire (kyrie, gloria, sanctus,
 *     anamnese, agnus, alleluia, acclamation) — la cote SECLI (rite C/AL) ne
 *     sait pas distinguer ces parties, le libellé est plus fiable.
 *
 * Usage :
 *   php bin/reclassify_repertoire.php --dry-run
 *   php bin/reclassify_repertoire.php
 *
 * Options :
 *   --dry-run         Affiche la table des transitions sans rien écrire.
 *   --prudent         Ne reclasse que les fiches de type '' ou 'entree'
 *                     (ne touche jamais un type déjà attribué).
 *   --force           Reclasse aussi les parties d'ordinaire déjà typées et les
 *                     fiches d'un ordinaire curé.
 *   --sans-mots-cles  Ne pas alimenter mots_cles avec le temps liturgique.
 *   --quiet           N'affiche que le bilan.
 *   -h, --help        Cette aide.
 */

use App\Database;
use App\Import\Secli;
use App\Import\TypeLiturgique;
use App\Models\RepertoireChant;
use App\SectionTypes;

if (PHP_SAPI !== 'cli') {
    exit("Ce script s'exécute en ligne de commande uniquement.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

$opts = getopt('h', ['dry-run', 'prudent', 'force', 'sans-mots-cles', 'quiet', 'help']);
if (isset($opts['h']) || isset($opts['help'])) {
    $doc = (string) file_get_contents(__FILE__);
    echo substr($doc, (int) strpos($doc, '/**'), (int) strpos($doc, '*/') + 2 - (int) strpos($doc, '/**')), "\n";
    exit(0);
}
$dryRun       = isset($opts['dry-run']);
$prudent      = isset($opts['prudent']);
$force        = isset($opts['force']);
$sansMotsCles = isset($opts['sans-mots-cles']);
$quiet        = isset($opts['quiet']);
$log = static function (string $msg) use ($quiet): void {
    if (!$quiet) {
        echo $msg, "\n";
    }
};

/** Sous-parties de l'ordinaire que le libellé peut distinguer (la cote C/AL ne le fait pas). */
const SOUS_PARTIES_ORDINAIRE = ['kyrie', 'gloria', 'sanctus', 'anamnese', 'agnus'];

/**
 * Types déjà attribués qu'une cote SECLI sûre ne doit pas écraser (sauf --force) :
 * ils viennent de mots-clés très distinctifs du libellé, plus fiables que le rite.
 * L'ordinaire de messe (rite C/AL indistinct) + la prière universelle (le rite U
 * recouvre à la fois l'alléluia et les refrains litaniques de PU).
 */
const TYPES_PROTEGES = [
    'kyrie', 'gloria', 'alleluia', 'acclamation', 'sanctus', 'anamnese', 'agnus',
    'priere_universelle',
];

$fiches = Database::all(
    'SELECT id, type, nom, code, ordinaire, mots_cles FROM repertoire_chants ORDER BY id'
);

$examinees = count($fiches);
$compteurs = [
    'reclassées'                => 0,
    'mots-clés enrichis seuls'  => 0,
    'ordinaire curé protégé'    => 0,
    'type protégé (ordinaire / PU)' => 0,
    'type inchangé (cote muette)' => 0,
    'type inchangé (déjà cohérent)' => 0,
    'type inchangé (--prudent)' => 0,
];
$transitions = [];

foreach ($fiches as $row) {
    $code = (string) $row['code'];
    $cur  = (string) $row['type'];
    $maj  = [];

    // 1. Type liturgique d'après le rite de la cote.
    $cible = null;
    if (!$force && trim((string) $row['ordinaire']) !== '') {
        $compteurs['ordinaire curé protégé']++;
    } elseif (!$force && in_array($cur, TYPES_PROTEGES, true)) {
        $compteurs['type protégé (ordinaire / PU)']++;
    } else {
        $cible = Secli::type($code);
        if ($cible === null && Secli::estOrdinaire($code)) {
            // C / AL : « ordinaire » sans plus — on tente la sous-partie via le
            // libellé stocké (nom + mots-clés), sinon on ne touche à rien.
            $devine = TypeLiturgique::motif(trim($row['nom'] . ' ' . $row['mots_cles']));
            $cible  = in_array($devine, SOUS_PARTIES_ORDINAIRE, true) ? $devine : null;
        }

        if ($cible === null) {
            $compteurs['type inchangé (cote muette)']++;
        } elseif ($cible === $cur) {
            $compteurs['type inchangé (déjà cohérent)']++;
        } elseif ($prudent && $cur !== '' && $cur !== TypeLiturgique::DEFAUT) {
            $compteurs['type inchangé (--prudent)']++;
        } else {
            $maj['type'] = $cible;
            // Le « nom » (badge du répertoire) suit le type : il n'est plus
            // éditable et vaut toujours un libellé de moment liturgique.
            if ((string) $row['nom'] !== SectionTypes::libelle($cible)) {
                $maj['nom'] = SectionTypes::libelle($cible);
            }
            $transitions[($cur === '' ? '(vide)' : $cur) . ' → ' . $cible]
                = ($transitions[($cur === '' ? '(vide)' : $cur) . ' → ' . $cible] ?? 0) + 1;
        }
    }

    // 2. Mots-clés de temps liturgique — indépendant du reclassement de type.
    if (!$sansMotsCles) {
        $fusion = RepertoireChant::fusionnerListe(
            (string) $row['mots_cles'],
            implode(', ', Secli::themes($code))
        );
        if ($fusion !== (string) $row['mots_cles']) {
            $maj['mots_cles'] = $fusion ?: null;
        }
    }

    if ($maj === []) {
        continue;
    }
    if (isset($maj['type'])) {
        $compteurs['reclassées']++;
    } else {
        $compteurs['mots-clés enrichis seuls']++;
    }
    if (!$dryRun) {
        RepertoireChant::update((int) $row['id'], $maj);
    }
}

$log('Reclassement du répertoire d\'après la cote SECLI');
$log(sprintf('  fiches examinées               : %d', $examinees));
foreach ($compteurs as $libelle => $n) {
    $log(sprintf('  %-29s : %d', $libelle, $n));
}
if ($transitions !== []) {
    $log('');
    arsort($transitions);
    foreach ($transitions as $libelle => $n) {
        $log(sprintf('  %-29s : %d', $libelle, $n));
    }
}

echo sprintf(
    "\n%d fiche(s) reclassée(s), %d à mots-clés enrichis%s.\n",
    $compteurs['reclassées'],
    $compteurs['mots-clés enrichis seuls'],
    $dryRun ? ' (dry-run, aucune écriture)' : ''
);

exit(0);
