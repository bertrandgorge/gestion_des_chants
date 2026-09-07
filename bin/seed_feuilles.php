<?php

declare(strict_types=1);

/**
 * Script de test : peuple N feuilles de messe dans une paroisse, avec des
 * chants tirés au hasard dans le répertoire partagé (repertoire_chants).
 *
 * Sert à générer des données réalistes pour tester des fonctionnalités qui
 * s'appuient sur l'historique des feuilles (ex. App\Models\Statistique) sans
 * saisir des messes une à une dans l'interface.
 *
 * Ne fait aucun appel réseau (contrairement à App\FeuilleService::creer, qui
 * interroge AELF) : les sections lecture/psaume/évangile sont créées vides,
 * seules les sections chant/ordinaire (App\SectionTypes) sont renseignées, à
 * partir d'une fiche choisie au hasard dans le répertoire pour leur type — si
 * le répertoire n'a aucune fiche de ce type, la section reste vide.
 *
 * Les feuilles sont espacées de --intervalle jours (7 par défaut, un rythme
 * dominical), la plus récente se terminant la veille de maintenant : toutes
 * sont donc déjà « passées » au sens de l'application.
 *
 * Usage :
 *   php bin/seed_feuilles.php --paroisse=saint-arnoux -n 50
 *   php bin/seed_feuilles.php --paroisse=saint-arnoux --clocher=roquefort -n 20 --intervalle=1
 *   php bin/seed_feuilles.php --paroisse=saint-arnoux -n 10 --dry-run
 *
 * Options :
 *   --paroisse=<slug>   Paroisse cible (obligatoire).
 *   --clocher=<slug>    Clocher cible (défaut : premier clocher de la paroisse).
 *   --chantre=<email>   Auteur des feuilles créées (défaut : premier utilisateur de la paroisse).
 *   -n, --nombre=<N>    Nombre de feuilles à créer (défaut : 20).
 *   --intervalle=<jours> Écart en jours entre deux feuilles (défaut : 7).
 *   --dry-run           N'écrit rien en base, affiche ce qui serait créé.
 *   --quiet             N'affiche que le bilan.
 *   -h, --help          Cette aide.
 */

use App\Database;
use App\Models\Chant;
use App\Models\Clocher;
use App\Models\FeuilleChant;
use App\Models\Paroisse;
use App\Models\Utilisateur;
use App\SectionTypes;

if (PHP_SAPI !== 'cli') {
    exit("Ce script s'exécute en ligne de commande uniquement.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

$opts = getopt('n:h', [
    'paroisse:', 'clocher:', 'chantre:', 'nombre:', 'intervalle:',
    'dry-run', 'quiet', 'help',
]);

if (isset($opts['h']) || isset($opts['help'])) {
    $doc = (string) file_get_contents(__FILE__);
    echo substr($doc, (int) strpos($doc, '/**'), (int) strpos($doc, '*/') + 2 - (int) strpos($doc, '/**')), "\n";
    exit(0);
}

$paroisseSlug = isset($opts['paroisse']) ? (string) $opts['paroisse'] : null;
if ($paroisseSlug === null) {
    fwrite(STDERR, "--paroisse=<slug> est obligatoire (voir --help).\n");
    exit(1);
}

$nombre = (int) ($opts['nombre'] ?? $opts['n'] ?? 20);
if ($nombre < 1) {
    fwrite(STDERR, "--nombre doit être un entier positif.\n");
    exit(1);
}
$intervalle = (int) ($opts['intervalle'] ?? 7);
if ($intervalle < 1) {
    fwrite(STDERR, "--intervalle doit être un entier positif.\n");
    exit(1);
}
$dryRun = isset($opts['dry-run']);
$quiet  = isset($opts['quiet']);
$log = static function (string $msg) use ($quiet): void {
    if (!$quiet) {
        echo $msg, "\n";
    }
};

// --- Résolution paroisse / clocher / chantre -----------------------------
$paroisse = Paroisse::findBySlug($paroisseSlug);
if ($paroisse === null) {
    fwrite(STDERR, "Paroisse « {$paroisseSlug} » introuvable.\n");
    exit(1);
}

$clochers = Clocher::forParoisse((int) $paroisse['id']);
if ($clochers === []) {
    fwrite(STDERR, "La paroisse « {$paroisse['nom']} » n'a aucun clocher : créez-en un depuis /admin/clochers d'abord.\n");
    exit(1);
}
$clocherSlug = isset($opts['clocher']) ? (string) $opts['clocher'] : null;
$clocher = $clocherSlug !== null
    ? current(array_filter($clochers, static fn (array $c) => $c['slug'] === $clocherSlug)) ?: null
    : $clochers[0];
if ($clocher === null) {
    fwrite(STDERR, "Clocher « {$clocherSlug} » introuvable dans « {$paroisse['nom']} ».\n");
    exit(1);
}

$chantreEmail = isset($opts['chantre']) ? (string) $opts['chantre'] : null;
$chantre = $chantreEmail !== null ? Utilisateur::findByEmail($chantreEmail) : (Utilisateur::forParoisse((int) $paroisse['id'])[0] ?? null);
if ($chantre === null) {
    fwrite(STDERR, "Aucun utilisateur trouvé pour « {$paroisse['nom']} » : créez-en un depuis /admin/utilisateurs d'abord.\n");
    exit(1);
} elseif ((int) $chantre['paroisse_id'] !== (int) $paroisse['id']) {
    fwrite(STDERR, "L'utilisateur « {$chantre['email']} » n'appartient pas à « {$paroisse['nom']} ».\n");
    exit(1);
}

// --- Répertoire disponible, par type de section chant/ordinaire ---------
// (seuls ces comportements sont proposés au répertoire — voir
// App\Controllers\RepertoireController::typesDisponibles)
$typesRepertoire = [];
foreach (SectionTypes::DEFAUT as $s) {
    if (in_array(SectionTypes::comportement($s['type']), ['chant', 'ordinaire'], true)) {
        $typesRepertoire[] = $s['type'];
    }
}

$parType = [];
foreach ($typesRepertoire as $type) {
    $parType[$type] = Database::all(
        "SELECT id, titre, code, auteur, chant, nb_couplets
         FROM repertoire_chants
         WHERE type = ? AND chant IS NOT NULL AND chant <> ''",
        [$type]
    );
}
foreach ($parType as $type => $fiches) {
    if ($fiches === []) {
        $log(sprintf('⚠ Aucune fiche du répertoire pour « %s » : ces sections resteront vides.', SectionTypes::libelle($type)));
    }
}

// --- Dates : $nombre feuilles espacées de $intervalle jours, la plus
//     récente se terminant la veille de maintenant (toutes déjà passées) --
$heureDefaut = $clocher['heure_defaut'] ?? '10:30:00';
[$h, $m] = array_map('intval', explode(':', (string) $heureDefaut));
$dernier = (new DateTimeImmutable('now'))->modify('-1 day')->setTime($h, $m);
if ($clocher['jour_defaut'] !== null) {
    $delta = ((int) $dernier->format('N') - (int) $clocher['jour_defaut'] + 7) % 7;
    $dernier = $dernier->modify("-{$delta} day");
}

// --- Création -------------------------------------------------------------
$log(sprintf(
    "Paroisse « %s » — clocher « %s » — chantre %s — %d feuille(s), espacées de %d jour(s), jusqu'au %s.%s",
    $paroisse['nom'],
    $clocher['nom'],
    $chantre['email'],
    $nombre,
    $intervalle,
    $dernier->format('d/m/Y H:i'),
    $dryRun ? ' (dry-run)' : ''
));

$crees = 0;
for ($i = $nombre - 1; $i >= 0; $i--) {
    $dateHeure = $dernier->modify('-' . ($i * $intervalle) . ' day');

    $choix = [];
    foreach ($parType as $type => $fiches) {
        if ($fiches !== []) {
            $choix[$type] = $fiches[random_int(0, count($fiches) - 1)];
        }
    }

    $log(sprintf(
        '• %s — %s',
        $dateHeure->format('d/m/Y H:i'),
        implode(', ', array_map(
            static fn ($t, $c) => SectionTypes::libelle($t) . ' : ' . $c['titre'],
            array_keys($choix),
            $choix
        )) ?: '(répertoire vide)'
    ));

    if ($dryRun) {
        continue;
    }

    Database::transaction(function () use ($dateHeure, $chantre, $clocher, $choix) {
        $feuilleId = FeuilleChant::create([
            'chantre_id' => (int) $chantre['id'],
            'clocher_id' => (int) $clocher['id'],
            'date_heure' => $dateHeure->format('Y-m-d H:i:s'),
        ]);

        $position = 0;
        foreach (SectionTypes::DEFAUT as $s) {
            $data = [
                'feuille_id' => $feuilleId,
                'nom'        => $s['nom'],
                'type'       => $s['type'],
                'position'   => $position++,
            ];
            if (isset($choix[$s['type']])) {
                $fiche = $choix[$s['type']];
                $data += [
                    'titre'         => $fiche['titre'],
                    'code'          => $fiche['code'],
                    'auteur'        => $fiche['auteur'],
                    'chant'         => $fiche['chant'],
                    'nb_couplets'   => $fiche['nb_couplets'],
                    'repertoire_id' => (int) $fiche['id'],
                ];
            }
            Chant::create($data);
        }
    });

    $crees++;
}

$log(sprintf("\n%d feuille(s) créée(s)%s.", $crees, $dryRun ? ' (dry-run, rien n\'a été écrit)' : ''));
exit(0);
