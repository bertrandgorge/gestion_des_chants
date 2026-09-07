<?php

declare(strict_types=1);

namespace App\Models;

use App\Database;
use App\Import\Paroles;
use App\Import\TypeLiturgique;

/**
 * Répertoire partagé de chants (dédoublonné, éditable), alimenté par les
 * imports externes (bin/import_repertoire.php, bin/import_url.php) et par les
 * chantres (bouton « Ajouter au répertoire » depuis une feuille).
 *
 * Toute la logique de dédoublonnage / fusion vit ici pour n'être écrite qu'une
 * fois : elle est utilisée à la fois par les scripts d'import, par le
 * contrôleur web et par bin/dedup_repertoire.php.
 *
 * Dédoublonnage : jamais entre deux lignes d'une même source (deux fiches
 * distinctes du même catalogue restent distinctes même si leur titre se
 * ressemble). Entre sources différentes, on compare le titre (hors caractères
 * spéciaux) ; en cas d'ambiguïté (plusieurs candidats de même titre), on
 * départage par un indice de correspondance (cote / code IEV Emmanuel /
 * mention « Emmanuel » dans les crédits — voir indiceEmmanuel()/indiceCode()),
 * puis par les deux premières lignes des paroles (meilleurCandidat()).
 */
final class RepertoireChant
{
    /**
     * Recherche multi-mots : chaque mot doit apparaître dans au moins un des
     * champs titre / code / auteur / mots-clés / source (URL d'import), tous
     * les mots étant requis. $texte étend la recherche aux paroles du chant.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function all(string $q = '', bool $texte = false): array
    {
        if ($q === '') {
            return Database::all('SELECT * FROM repertoire_chants ORDER BY titre ASC LIMIT 500');
        }

        $templates = [
            'titre LIKE ?',
            'code LIKE ?',
            'auteur LIKE ?',
            'mots_cles LIKE ?',
            'ordinaire LIKE ?',
            'EXISTS (SELECT 1 FROM import_journal j WHERE j.chant_id = repertoire_chants.id AND j.url LIKE ?)',
        ];
        if ($texte) {
            $templates[] = 'chant LIKE ?';
        }
        [$where, $params] = Database::likeMots($q, $templates);

        return Database::all(
            "SELECT * FROM repertoire_chants WHERE $where ORDER BY titre ASC LIMIT 500",
            $params
        );
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM repertoire_chants WHERE id = ?', [$id]);
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return Database::insert('repertoire_chants', $data);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($data !== []) {
            Database::update('repertoire_chants', $data, ['id' => $id]);
        }
    }

    public static function delete(int $id): void
    {
        Database::delete('repertoire_chants', ['id' => $id]);
    }

    /**
     * URLs sources d'une fiche du répertoire (après dédoublonnage : une par
     * source ayant contribué à la fiche).
     *
     * @return array<int,array{source:string,ref:string,url:?string}>
     */
    public static function urls(int $id): array
    {
        return Database::all(
            'SELECT source, ref, url FROM import_journal WHERE chant_id = ? ORDER BY source, ref',
            [$id]
        );
    }

    /** La fiche $id a-t-elle déjà une ligne d'import de cette source ? */
    public static function aSource(int $id, string $source): bool
    {
        return (int) Database::value(
            'SELECT COUNT(*) FROM import_journal WHERE chant_id = ? AND source = ?',
            [$id, $source]
        ) > 0;
    }

    /**
     * Regroupe toutes les fiches du répertoire par titre réduit (hors accents,
     * ponctuation, espaces et casse — App\Models\Chant::reduire) et ne renvoie
     * que les groupes d'au moins deux fiches : des doublons probables à
     * fusionner. Chaque fiche est enrichie de ses URLs sources et des deux
     * premières lignes de ses paroles, pour comparaison visuelle rapide.
     *
     * @return list<array{titre_reduit:string,chants:array<int,array<string,mixed>>}>
     */
    public static function doublons(): array
    {
        $groupes = [];
        foreach (Database::all('SELECT id, titre, auteur, chant, nb_couplets FROM repertoire_chants ORDER BY titre ASC') as $ligne) {
            $groupes[Chant::reduire((string) $ligne['titre'])][] = $ligne;
        }

        $resultat = [];
        foreach ($groupes as $cle => $chants) {
            if (count($chants) < 2) {
                continue;
            }
            $resultat[] = [
                'titre_reduit' => $cle,
                'chants'       => array_map(static fn (array $c) => self::avecApercu($c), $chants),
            ];
        }

        usort($resultat, static fn (array $a, array $b) => strcasecmp(
            (string) $a['chants'][0]['titre'],
            (string) $b['chants'][0]['titre']
        ));

        return $resultat;
    }

    /**
     * Fiches du répertoire partageant un même ordinaire de messe (Kyrie, Gloria,
     * Sanctus, Anamnèse, Agnus… d'une même musique), une par type. Sert à la
     * reprise groupée sur une feuille de chant (App\Controllers\ChantController::reprendreOrdinaire).
     *
     * @return array<int,array<string,mixed>> indexé par type
     */
    public static function pourOrdinaire(string $ordinaire): array
    {
        $parType = [];
        foreach (Database::all(
            'SELECT * FROM repertoire_chants WHERE ordinaire = ? ORDER BY id ASC',
            [$ordinaire]
        ) as $ligne) {
            $parType[(string) $ligne['type']] = $ligne;
        }

        return $parType;
    }

    /**
     * Autres fiches du répertoire partageant le même ordinaire de messe que
     * $excludeId, à l'exclusion d'elle-même. Sert à l'affichage de la fiche
     * (« Chants de la même messe »), symétrique de memeTitre() ci-dessous.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function memeOrdinaire(int $excludeId, ?string $ordinaire): array
    {
        if ($ordinaire === null || trim($ordinaire) === '') {
            return [];
        }

        $candidats = Database::all(
            'SELECT id, titre, type, nom, code FROM repertoire_chants WHERE ordinaire = ? AND id <> ? ORDER BY id ASC',
            [$ordinaire, $excludeId]
        );

        usort($candidats, static fn (array $a, array $b) => strcasecmp((string) $a['nom'], (string) $b['nom']));

        return $candidats;
    }

    /**
     * Fiches du répertoire dont le titre réduit correspond à $titre (hors
     * accents/ponctuation/espaces/casse), à l'exclusion de $excludeId. Sert à
     * repérer, depuis la fiche d'un chant, d'éventuels doublons non fusionnés.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function memeTitre(int $excludeId, string $titre): array
    {
        $cible = Chant::reduire($titre);
        $candidats = [];
        foreach (Database::all('SELECT id, titre, code, chant FROM repertoire_chants WHERE id <> ?', [$excludeId]) as $ligne) {
            if (Chant::reduire((string) $ligne['titre']) === $cible) {
                $candidats[] = self::avecApercu($ligne);
            }
        }

        usort($candidats, static fn (array $a, array $b) => strcasecmp((string) $a['titre'], (string) $b['titre']));

        return $candidats;
    }

    /**
     * Enrichit une ligne de repertoire_chants (id, titre, chant…) de ses URLs
     * sources et des deux premières lignes de ses paroles telles quelles (pas
     * réduites, contrairement à App\Models\Chant::premieresLignes qui sert au
     * dédoublonnage automatique) : sert à l'affichage pour comparaison visuelle.
     *
     * @param array<string,mixed> $ligne
     * @return array<string,mixed>
     */
    private static function avecApercu(array $ligne): array
    {
        $ligne['urls'] = self::urls((int) $ligne['id']);
        $lignesTexte = [];
        foreach (preg_split('~\r\n|\r|\n~', trim((string) $ligne['chant'])) ?: [] as $l) {
            $l = trim($l);
            if ($l === '') {
                continue;
            }
            $lignesTexte[] = $l;
            if (count($lignesTexte) >= 2) {
                break;
            }
        }
        $ligne['premieres_lignes'] = $lignesTexte;

        return $ligne;
    }

    /**
     * id d'une fiche du répertoire correspondant à $titre/$chant, ou null.
     * $sourceActuelle (si fournie) exclut les fiches ayant déjà une ligne de
     * cette source (jamais de dédoublonnage au sein d'une même source).
     * $excludeId exclut une fiche donnée (ex. celle qu'on est en train de
     * compléter).
     */
    public static function trouverDoublon(
        string $titre,
        string $chant,
        ?string $code,
        ?string $codeRepertoire,
        ?string $sourceActuelle,
        ?int $excludeId = null
    ): ?int {
        $candidats = [];
        foreach (Database::all('SELECT id, titre, chant, code, auteur FROM repertoire_chants') as $row) {
            $id = (int) $row['id'];
            if ($id === $excludeId) {
                continue;
            }
            if ($sourceActuelle !== null && self::aSource($id, $sourceActuelle)) {
                continue;
            }
            $candidats[] = [
                'cle'   => $id,
                'titre' => (string) $row['titre'],
                'chant' => (string) $row['chant'],
                'code'  => (string) $row['code'],
                'auteur' => (string) $row['auteur'],
            ];
        }

        $source = ['titre' => $titre, 'chant' => $chant, 'code' => $code, 'code_repertoire' => $codeRepertoire];

        /** @var int|null */
        return self::meilleurCandidat(
            $source,
            $candidats,
            static fn (array $s, array $c) => self::indiceEmmanuel($s, $c) || self::indiceCode($s, $c)
        );
    }

    /**
     * Cherche, parmi $candidats, celui qui correspond le mieux à $source :
     *   1. titre identique hors caractères spéciaux (App\Models\Chant::reduire) —
     *      s'il n'y en a qu'un, c'est lui ;
     *   2. sinon, le premier que $disambiguer($source, $candidat) reconnaît ;
     *   3. sinon, le premier dont les deux premières lignes des paroles
     *      correspondent (App\Models\Chant::premieresLignes).
     * Renvoie null si aucun titre ne correspond, ou si plusieurs candidats
     * restent ambigus après ces trois étapes.
     *
     * @param array{titre:string,chant:string} $source
     * @param array<int,array{cle:mixed,titre:string,chant:string}> $candidats
     * @param callable(array,array):bool $disambiguer
     */
    public static function meilleurCandidat(array $source, array $candidats, callable $disambiguer): mixed
    {
        $titreCible = Chant::reduire($source['titre']);
        $memeTitre = array_values(array_filter(
            $candidats,
            static fn (array $c) => Chant::reduire($c['titre']) === $titreCible
        ));

        if ($memeTitre === []) {
            return null;
        }
        if (count($memeTitre) === 1) {
            return $memeTitre[0]['cle'];
        }

        foreach ($memeTitre as $c) {
            if ($disambiguer($source, $c)) {
                return $c['cle'];
            }
        }

        $premieresSource = Chant::premieresLignes($source['chant']);
        if ($premieresSource !== '') {
            foreach ($memeTitre as $c) {
                if (Chant::premieresLignes($c['chant']) === $premieresSource) {
                    return $c['cle'];
                }
            }
        }

        return null;
    }

    /**
     * Indice « chant Emmanuel » : le candidat porte le même code IEV que la
     * source, ou « Emmanuel » figure dans ses crédits (auteur). Utilisé pour
     * départager les doublons de la source catechisme-emmanuel.
     *
     * @param array{code_repertoire?:?string} $source
     * @param array{code?:?string,auteur?:?string} $candidat
     */
    public static function indiceEmmanuel(array $source, array $candidat): bool
    {
        $codeSource = trim((string) ($source['code_repertoire'] ?? ''));
        if ($codeSource !== '' && self::contientCode((string) ($candidat['code'] ?? ''), $codeSource)) {
            return true;
        }

        $auteur = (string) ($candidat['auteur'] ?? '');

        return $auteur !== '' && stripos(Paroles::sansAccents($auteur), 'emmanuel') !== false;
    }

    /**
     * Indice « même cote » : au moins un des codes de la source figure parmi
     * les codes du candidat. Utilisé pour départager les doublons entre
     * chorale-pole-fontainebleau et chantonseneglise (toutes deux capturent
     * une cote Secli).
     *
     * @param array{code?:?string} $source
     * @param array{code?:?string} $candidat
     */
    public static function indiceCode(array $source, array $candidat): bool
    {
        foreach (self::codes((string) ($source['code'] ?? '')) as $code) {
            if (self::contientCode((string) ($candidat['code'] ?? ''), $code)) {
                return true;
            }
        }

        return false;
    }

    /** Le code $code figure-t-il dans la liste « a, b, c » $liste (insensible à la casse) ? */
    public static function contientCode(string $liste, string $code): bool
    {
        $code = mb_strtolower(trim($code));
        if ($code === '') {
            return false;
        }
        foreach (self::codes($liste) as $item) {
            if (mb_strtolower($item) === $code) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function codes(string $liste): array
    {
        return preg_split('~\s*,\s*~', trim($liste), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Insère ou complète une fiche du répertoire à partir d'une ligne
     * fraîchement analysée (import unitaire ou passe d'import de masse).
     * $source sert à exclure les fiches déjà pourvues d'une ligne de la même
     * source lors de la recherche de doublon (jamais au sein d'une source).
     *
     * @param array{titre:string,code:?string,code_repertoire?:?string,auteur:?string,type:string,nom:?string,chant:string,nb_couplets:int} $ligne
     * @param int|null $chantIdActuel  id du répertoire déjà lié (relance d'un import), s'il existe encore
     * @param string|null $motCle      catégorie brute de la source à ajouter aux mots-clés (null pour l'ignorer)
     */
    public static function upsertDepuisImport(string $source, array $ligne, ?int $chantIdActuel, ?string $motCle): int
    {
        $cible = ($chantIdActuel !== null && self::find($chantIdActuel) !== null)
            ? $chantIdActuel
            : self::trouverDoublon(
                $ligne['titre'],
                $ligne['chant'],
                $ligne['code'] ?? null,
                $ligne['code_repertoire'] ?? null,
                $source
            );

        if ($cible === null) {
            return self::creerDepuisImport($ligne, $motCle);
        }

        self::completer($cible, $ligne, $motCle);

        return $cible;
    }

    /**
     * Crée directement une nouvelle fiche à partir d'une ligne analysée, sans
     * chercher de doublon (contrairement à upsertDepuisImport). Utile quand
     * l'appelant a déjà établi qu'aucune correspondance n'existe (ex. la
     * recherche par lots de bin/import_repertoire.php --reset, qui évite ainsi
     * de rechercher un doublon fiche par fiche sur un répertoire qui grossit
     * — coûteux sur plusieurs milliers de fiches).
     *
     * @param array{titre:string,code:?string,code_repertoire?:?string,auteur:?string,type:string,nom:?string,ordinaire?:?string,chant:string,nb_couplets:int} $ligne
     */
    public static function creerDepuisImport(array $ligne, ?string $motCle): int
    {
        $code = self::fusionnerListe((string) ($ligne['code'] ?? ''), (string) ($ligne['code_repertoire'] ?? ''));

        return self::create([
            'titre'       => $ligne['titre'],
            'code'        => self::videEnNull($code),
            'auteur'      => self::videEnNull(trim((string) ($ligne['auteur'] ?? ''))),
            'type'        => $ligne['type'] !== '' ? $ligne['type'] : TypeLiturgique::DEFAUT,
            'nom'         => self::videEnNull((string) ($ligne['nom'] ?? '')),
            'ordinaire'   => self::videEnNull((string) ($ligne['ordinaire'] ?? '')),
            'chant'       => $ligne['chant'],
            'nb_couplets' => $ligne['nb_couplets'],
            'mots_cles'   => self::videEnNull(trim((string) $motCle)),
        ]);
    }

    /**
     * Fusionne $perdantId dans $survivantId (complète le survivant avec le
     * meilleur du perdant, repointe les chants de feuille et le journal
     * d'import, puis supprime le perdant). Ne fait rien si les deux id sont
     * égaux ou si le perdant n'existe plus.
     */
    public static function mergerDans(int $survivantId, int $perdantId): void
    {
        if ($survivantId === $perdantId) {
            return;
        }
        $perdant = self::find($perdantId);
        if ($perdant === null) {
            return;
        }

        self::completer($survivantId, [
            'titre'       => (string) $perdant['titre'],
            'code'        => $perdant['code'],
            'auteur'      => $perdant['auteur'],
            'type'        => (string) $perdant['type'],
            'nom'         => $perdant['nom'],
            'ordinaire'   => $perdant['ordinaire'],
            'chant'       => (string) $perdant['chant'],
            'nb_couplets' => (int) $perdant['nb_couplets'],
        ], $perdant['mots_cles']);

        Database::run('UPDATE chants SET repertoire_id = ? WHERE repertoire_id = ?', [$survivantId, $perdantId]);
        Database::run('UPDATE import_journal SET chant_id = ? WHERE chant_id = ?', [$survivantId, $perdantId]);
        self::delete($perdantId);
    }

    /**
     * Sépare une source (ligne import_journal) de la fiche à laquelle elle est
     * actuellement liée, et la réimporte comme fiche indépendante — sans
     * dédoublonnage. Sert quand deux chants partagent les mêmes paroles mais
     * pas la même musique (un cas que le dédoublonnage par titre ne peut pas
     * distinguer) et ont été fusionnés à tort.
     *
     * La fiche d'origine n'est pas modifiée : seule cette ligne de journal la
     * quitte pour une nouvelle fiche.
     *
     * @return int|null id de la nouvelle fiche, ou null si la ligne n'existe
     *                   pas ou n'est liée à aucune fiche.
     */
    public static function separerImport(string $source, string $ref): ?int
    {
        $j = Database::one(
            'SELECT titre, code, code_repertoire, auteur, type, nom, categorie, chant, chant_id
               FROM import_journal WHERE source = ? AND ref = ?',
            [$source, $ref]
        );
        if ($j === null || $j['chant_id'] === null) {
            return null;
        }

        $chant = Paroles::format((string) $j['chant']);
        // La catégorie brute de chantonseneglise n'est pas fiable comme mot-clé
        // (même convention qu'à l'import — voir bin/import_repertoire.php).
        $motCle = $source !== 'chantonseneglise' ? (string) $j['categorie'] : '';
        $code = self::fusionnerListe((string) ($j['code'] ?? ''), (string) ($j['code_repertoire'] ?? ''));

        $nouveau = self::create([
            'titre'       => (string) $j['titre'],
            'code'        => self::videEnNull($code),
            'auteur'      => self::videEnNull((string) ($j['auteur'] ?? '')),
            'type'        => (string) ($j['type'] ?: TypeLiturgique::DEFAUT),
            'nom'         => self::videEnNull((string) ($j['nom'] ?? '')),
            'chant'       => $chant,
            'nb_couplets' => count_couplets($chant, false),
            'mots_cles'   => self::videEnNull($motCle),
        ]);

        Database::run(
            'UPDATE import_journal SET chant_id = ? WHERE source = ? AND ref = ?',
            [$nouveau, $source, $ref]
        );

        return $nouveau;
    }

    /**
     * Départage deux fiches pour une fusion automatique : la même règle que
     * l'ancien bin/dedup_chants.php (paroles les plus propres, puis le plus de
     * couplets, puis les paroles les plus longues, puis le plus petit id).
     *
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array{0:array<string,mixed>,1:array<string,mixed>} [survivant, perdant]
     */
    public static function meilleur(array $a, array $b): array
    {
        $cle = static fn (array $f) => [
            Chant::scoreQualite((string) $f['chant']),
            -(int) $f['nb_couplets'],
            -mb_strlen((string) $f['chant']),
            (int) $f['id'],
        ];

        return $cle($a) <= $cle($b) ? [$a, $b] : [$b, $a];
    }

    /**
     * Fusionne deux listes « a, b, c » séparées par des virgules : concatène et
     * déduplique (insensible à la casse, garde la casse de la première
     * occurrence, préserve l'ordre).
     */
    public static function fusionnerListe(string $a, string $b): string
    {
        $items = [];
        $vus = [];
        foreach ([$a, $b] as $liste) {
            foreach (self::codes($liste) as $item) {
                $cle = mb_strtolower($item);
                if (!isset($vus[$cle])) {
                    $vus[$cle] = true;
                    $items[] = $item;
                }
            }
        }

        return implode(', ', $items);
    }

    /**
     * Complète une fiche existante avec les données d'une nouvelle source :
     * paroles plus complètes, codes (y compris code IEV) / mots-clés fusionnés
     * (union), cote / auteur manquants, classement (type / nom) s'il était
     * resté au défaut.
     *
     * @param array{titre?:string,code?:?string,code_repertoire?:?string,auteur?:?string,type?:string,nom?:?string,chant:string,nb_couplets:int} $ligne
     */
    private static function completer(int $id, array $ligne, ?string $motCle): void
    {
        $actuel = self::find($id);
        if ($actuel === null) {
            return;
        }

        $maj = [];

        $scoreNouveau = Chant::scoreQualite($ligne['chant']);
        $scoreActuel  = Chant::scoreQualite((string) $actuel['chant']);
        $nbNouveau    = (int) $ligne['nb_couplets'];
        $nbActuel     = (int) $actuel['nb_couplets'];
        if ($scoreNouveau < $scoreActuel || ($nbNouveau > $nbActuel && $scoreNouveau <= $scoreActuel)) {
            $maj['chant']       = $ligne['chant'];
            $maj['nb_couplets'] = $nbNouveau;
        }

        $codeNouveau = self::fusionnerListe((string) ($ligne['code'] ?? ''), (string) ($ligne['code_repertoire'] ?? ''));
        $code = self::fusionnerListe((string) $actuel['code'], $codeNouveau);
        if ($code !== (string) $actuel['code']) {
            $maj['code'] = self::videEnNull($code);
        }

        if (trim((string) $actuel['auteur']) === '' && trim((string) ($ligne['auteur'] ?? '')) !== '') {
            $maj['auteur'] = $ligne['auteur'];
        }

        $typeActuel = (string) $actuel['type'];
        $typeNouveau = (string) ($ligne['type'] ?? '');
        if (($typeActuel === '' || $typeActuel === TypeLiturgique::DEFAUT)
            && $typeNouveau !== '' && $typeNouveau !== TypeLiturgique::DEFAUT
        ) {
            $maj['type'] = $typeNouveau;
            $maj['nom']  = ($ligne['nom'] ?? '') !== '' ? $ligne['nom'] : $actuel['nom'];
        }

        $ordinaireNouveau = trim((string) ($ligne['ordinaire'] ?? ''));
        if ($ordinaireNouveau !== '' && $ordinaireNouveau !== (string) $actuel['ordinaire']) {
            $maj['ordinaire'] = $ordinaireNouveau;
        }

        $motsCles = self::fusionnerListe((string) $actuel['mots_cles'], (string) $motCle);
        if ($motsCles !== (string) $actuel['mots_cles']) {
            $maj['mots_cles'] = self::videEnNull($motsCles);
        }

        self::update($id, $maj);
    }

    private static function videEnNull(string $s): ?string
    {
        return $s === '' ? null : $s;
    }
}
