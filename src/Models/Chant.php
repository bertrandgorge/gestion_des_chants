<?php

declare(strict_types=1);

namespace App\Models;

use App\Database;

final class Chant
{
    /** Colonnes recopiées d'une section à l'autre (duplication de feuille). */
    public const FIELDS = ['titre', 'code', 'auteur', 'chant', 'nb_couplets', 'introduction', 'contenu', 'acclamation', 'reference', 'url', 'repertoire_id'];

    /** @return array<int,array<string,mixed>> */
    public static function forFeuille(int $feuilleId): array
    {
        return Database::all(
            'SELECT * FROM chants WHERE feuille_id = ? ORDER BY position ASC, id ASC',
            [$feuilleId]
        );
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM chants WHERE id = ?', [$id]);
    }

    /** Section + feuille + paroisse (contrôle multi-tenant). */
    public static function findForParoisse(int $id, int $paroisseId): ?array
    {
        return Database::one(
            'SELECT ch.*, f.id AS feuille_id
             FROM chants ch
             JOIN feuilles_chant f ON f.id = ch.feuille_id
             JOIN clochers c ON c.id = f.clocher_id
             WHERE ch.id = ? AND c.paroisse_id = ?',
            [$id, $paroisseId]
        );
    }

    public static function create(array $data): int
    {
        return Database::insert('chants', $data);
    }

    public static function update(int $id, array $data): void
    {
        Database::update('chants', $data, ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        Database::delete('chants', ['id' => $id]);
    }

    public static function nextPosition(int $feuilleId): int
    {
        return (int) Database::value(
            'SELECT COALESCE(MAX(position), -1) + 1 FROM chants WHERE feuille_id = ?',
            [$feuilleId]
        );
    }

    /**
     * Clé de dédoublonnage d'un chant : titre + première ligne du refrain,
     * réduits à leurs seuls caractères significatifs (lettres et chiffres, sans
     * accents ni casse). Un même chant importé de plusieurs sources — titres
     * ponctués différemment, cote Secli absente ou divergente — produit la même
     * clé ; deux chants homonymes mais distincts (refrains différents) non.
     */
    public static function cleDedup(string $titre, ?string $chant): string
    {
        return self::reduire($titre) . '|' . self::reduire(self::ligneRefrain((string) $chant));
    }

    /**
     * Nombre de lignes « parasites » (crédits, copyright, mentions éditoriales)
     * dans des paroles. Sert à départager deux fiches d'un même chant : la plus
     * propre l'emporte, à couplets égaux.
     */
    public static function scoreQualite(?string $chant): int
    {
        $n = 0;
        foreach (explode("\n", (string) $chant) as $ligne) {
            $l = trim($ligne);
            if ($l === '') {
                continue;
            }
            if (str_contains($l, '©')
                || preg_match(
                    '~^(?:\(c\)\s*\d{4}'
                    . '|paroles\s+et\s+musique\b'
                    . '|(?:paroles|musique|texte|traduction|harmonisation|adaptation|arrangements?|orchestration|arr\.)\s*[:/]'
                    . '|titre original\b'
                    . '|\d{4},?\s+[ÉEée]dition)~iu',
                    $l
                )
            ) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Réduit une chaîne à ses lettres/chiffres, sans accents ni casse. Sert à
     * comparer des titres/codes « hors caractères spéciaux » (App\Models\RepertoireChant).
     * Mémoïsée : appelée en boucle serrée sur les mêmes titres lors du
     * rapprochement en masse (bin/import_repertoire.php --reset).
     */
    public static function reduire(string $s): string
    {
        static $cache = [];
        if (isset($cache[$s])) {
            return $cache[$s];
        }

        if (function_exists('transliterator_transliterate')) {
            $reduit = (string) transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
        } else {
            $reduit = strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s));
        }
        $reduit = (string) preg_replace('~[^a-z0-9]+~', '', $reduit);

        return $cache[$s] = $reduit;
    }

    /**
     * Empreinte des $n premières lignes non vides d'un texte de chant (paroles
     * déjà mises en forme — voir App\Import\Paroles::format), réduites (sans
     * accents/ponctuation/casse) pour une comparaison robuste entre sources.
     * Sert de repli quand le titre seul ne suffit pas à départager des doublons
     * (App\Models\RepertoireChant::meilleurCandidat). Chaîne vide si le texte
     * n'a aucune ligne exploitable.
     *
     * Chaque ligne réduite est tronquée à 120 caractères (une ligne sans retour
     * peut être très longue) : le résultat tient toujours dans la colonne
     * import_journal.empreinte_paroles (VARCHAR(255)) où il est mémorisé.
     */
    public static function premieresLignes(string $chant, int $n = 2): string
    {
        $lignes = [];
        foreach (preg_split('~\r\n|\r|\n~', trim($chant)) ?: [] as $ligne) {
            $ligne = mb_substr(self::reduire($ligne), 0, 120);
            if ($ligne === '') {
                continue;
            }
            $lignes[] = $ligne;
            if (count($lignes) >= $n) {
                break;
            }
        }

        return implode('|', $lignes);
    }

    /**
     * Première ligne du refrain d'un chant mis en forme (parties séparées par une
     * ligne vide, refrain préfixé « R/ », couplets « 1. »…). À défaut de refrain :
     * première ligne du premier couplet ; à défaut : première ligne non vide.
     */
    private static function ligneRefrain(string $chant): string
    {
        $parties = preg_split('~\n\s*\n~', trim(str_replace("\r\n", "\n", $chant))) ?: [];
        $refrain = $couplet = null;
        foreach ($parties as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            if ($refrain === null && preg_match('~^R\s*/\s*(.*)~s', $p, $m)) {
                $refrain = $m[1];
            } elseif ($couplet === null && preg_match('~^\d{1,2}\s*[.)]\s*(.*)~s', $p, $m)) {
                $couplet = $m[1];
            }
        }
        $bloc = $refrain ?? $couplet ?? (string) ($parties[0] ?? '');

        return trim(explode("\n", trim($bloc))[0]);
    }

    /**
     * Recherche dans l'historique des chants d'une paroisse (feuilles passées)
     * et dans le répertoire partagé. Regroupe par clé de dédoublonnage et
     * conserve la version avec le plus de couplets.
     *
     * Sources : les autres feuilles de la paroisse dont le chant ne provient pas
     * déjà du répertoire (sinon déjà couvert par la recherche répertoire), et le
     * répertoire partagé (App\Models\RepertoireChant, non filtré par paroisse).
     * La feuille en cours d'édition est exclue via $excludeFeuilleId. Les champs
     * « url »/« repertoire_id »/« source_label » (provenance : « Répertoire » ou
     * « Feuille du … ») ne sont exposés qu'ici, pour l'interface chantre.
     *
     * Recherche multi-mots : chaque mot doit apparaître dans au moins un des
     * champs titre / code / auteur / source (URL), tous les mots étant requis.
     * $texte étend la recherche aux paroles du chant.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function historique(int $paroisseId, string $q, ?string $type = null, ?int $excludeFeuilleId = null, bool $texte = false): array
    {
        $templatesHistorique = ['ch.titre LIKE ?', 'ch.code LIKE ?', 'ch.auteur LIKE ?', 'ch.url LIKE ?'];
        if ($texte) {
            $templatesHistorique[] = 'ch.chant LIKE ?';
        }
        [$ouHistorique, $paramsHistorique] = Database::likeMots($q, $templatesHistorique);

        $historique = Database::all(
            "SELECT ch.titre, ch.code, ch.auteur, ch.chant, ch.nb_couplets, ch.type, ch.feuille_id, ch.url,
                    f.date_heure AS feuille_date
             FROM chants ch
             JOIN feuilles_chant f ON f.id = ch.feuille_id
             JOIN clochers c ON c.id = f.clocher_id
             WHERE c.paroisse_id = ?
               AND f.id <> ?
               AND ch.repertoire_id IS NULL
               AND ch.chant IS NOT NULL AND ch.chant <> ''
               AND ($ouHistorique)
             ORDER BY f.date_heure DESC
             LIMIT 300",
            [$paroisseId, $excludeFeuilleId ?? 0, ...$paramsHistorique]
        );

        $templatesRepertoire = [
            'r.titre LIKE ?',
            'r.code LIKE ?',
            'r.auteur LIKE ?',
            'r.mots_cles LIKE ?',
            'EXISTS (SELECT 1 FROM import_journal j2 WHERE j2.chant_id = r.id AND j2.url LIKE ?)',
        ];
        if ($texte) {
            $templatesRepertoire[] = 'r.chant LIKE ?';
        }
        [$ouRepertoire, $paramsRepertoire] = Database::likeMots($q, $templatesRepertoire);

        $repertoire = Database::all(
            "SELECT r.id AS repertoire_id, r.titre, r.code, r.auteur, r.chant, r.nb_couplets, r.type, r.ordinaire,
                    (SELECT j.url FROM import_journal j WHERE j.chant_id = r.id ORDER BY j.traite_le DESC LIMIT 1) AS url
             FROM repertoire_chants r
             WHERE r.chant IS NOT NULL AND r.chant <> ''
               AND ($ouRepertoire)
             ORDER BY r.titre ASC
             LIMIT 300",
            $paramsRepertoire
        );

        $groups = [];
        $typesByKey = [];
        foreach ([...$historique, ...$repertoire] as $row) {
            $key = self::cleDedup((string) $row['titre'], (string) $row['chant']);
            $typesByKey[$key][$row['type']] = true;

            // Version la plus complète : on privilégie le plus de couplets (hors refrain).
            $couplets = isset($row['nb_couplets']) && $row['nb_couplets'] !== null
                ? (int) $row['nb_couplets']
                : count_couplets($row['chant'], false);
            $rid = isset($row['repertoire_id']) ? (int) $row['repertoire_id'] : null;

            if (!isset($groups[$key]) || $couplets > $groups[$key]['_couplets']) {
                $groups[$key] = [
                    'titre'         => $row['titre'],
                    'code'          => $row['code'],
                    'auteur'        => $row['auteur'],
                    'chant'         => $row['chant'],
                    'feuille_id'    => isset($row['feuille_id']) ? (int) $row['feuille_id'] : null,
                    'feuille_date'  => $row['feuille_date'] ?? null,
                    'repertoire_id' => $groups[$key]['repertoire_id'] ?? $rid,
                    'ordinaire'     => $groups[$key]['ordinaire'] ?? ($row['ordinaire'] ?? null),
                    'url'           => $groups[$key]['url'] ?? ($row['url'] ?? null),
                    '_couplets'     => $couplets,
                ];
            }

            // Le lien vers le répertoire, l'ordinaire et l'URL de partition ne
            // dépendent pas du nombre de couplets : dès qu'une des lignes groupées
            // les porte, on les conserve (l'historique paroissial ne renvoie que
            // des chants non liés — voir la requête ci-dessus).
            $groups[$key]['repertoire_id'] ??= $rid;
            $groups[$key]['ordinaire'] ??= $row['ordinaire'] ?? null;
            $groups[$key]['url'] ??= $row['url'] ?? null;
        }

        $result = [];
        foreach ($groups as $key => $g) {
            $g['types'] = array_keys($typesByKey[$key]);
            // Provenance affichée dans la recherche : le répertoire l'emporte
            // (c'est lui qui sera lié à la section) ; sinon la feuille d'où le
            // chant a été repris.
            $g['source_label'] = $g['repertoire_id'] !== null
                ? 'Répertoire'
                : ($g['feuille_date'] !== null ? 'Feuille du ' . self::dateCourte((string) $g['feuille_date']) : null);
            unset($g['_couplets']);
            $result[] = $g;
        }

        // Les correspondances du type demandé d'abord.
        if ($type !== null) {
            usort($result, static fn ($a, $b) => (in_array($type, $b['types'], true) <=> in_array($type, $a['types'], true)));
        }

        return array_slice($result, 0, 20);
    }

    /**
     * Chants déjà utilisés dans la paroisse, pour la section $type, sur des
     * messes passées qui partageaient au moins une des lectures (1re/2e lecture,
     * psaume, évangile) de la feuille $feuilleId. Sert d'aide au choix dans
     * l'édition d'une section (App\Controllers\ChantController::editSection).
     *
     * Les lectures se rejouant sur un cycle de trois ans, deux messes qui ont la
     * même référence de lecture traitent des mêmes textes : les chants retenus
     * alors sont de bons candidats. Regroupé par chant (repertoire_id, repli sur
     * la clé de dédoublonnage), version la plus récente d'abord.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function pourMemesLectures(int $feuilleId, string $type, int $paroisseId, int $limit = 8): array
    {
        $lectures = ['premiere_lecture', 'deuxieme_lecture', 'psaume', 'evangile'];
        $in = implode(',', array_fill(0, count($lectures), '?'));

        $refs = Database::all(
            "SELECT reference FROM chants
             WHERE feuille_id = ? AND type IN ($in)
               AND reference IS NOT NULL AND TRIM(reference) <> ''",
            [$feuilleId, ...$lectures]
        );
        $refs = array_values(array_unique(array_map(
            static fn ($r) => trim((string) $r['reference']),
            $refs
        )));
        if ($refs === []) {
            return [];
        }
        $inRefs = implode(',', array_fill(0, count($refs), '?'));

        $rows = Database::all(
            "SELECT ch.id, ch.titre, ch.code, ch.auteur, ch.chant, ch.nb_couplets, ch.url,
                    ch.repertoire_id, r.ordinaire,
                    f.date_heure AS feuille_date, c.nom AS clocher_nom
             FROM chants ch
             JOIN feuilles_chant f ON f.id = ch.feuille_id
             JOIN clochers c ON c.id = f.clocher_id
             LEFT JOIN repertoire_chants r ON r.id = ch.repertoire_id
             WHERE c.paroisse_id = ?
               AND ch.type = ?
               AND f.id <> ?
               AND f.date_heure < (NOW() - INTERVAL 6 HOUR)
               AND (TRIM(COALESCE(ch.titre, '')) <> '' OR TRIM(COALESCE(ch.chant, '')) <> '')
               AND EXISTS (
                   SELECT 1 FROM chants lect
                   WHERE lect.feuille_id = f.id
                     AND lect.type IN ($in)
                     AND TRIM(lect.reference) IN ($inRefs)
               )
             ORDER BY f.date_heure DESC
             LIMIT 100",
            [$paroisseId, $type, $feuilleId, ...$lectures, ...$refs]
        );

        $groups = [];
        foreach ($rows as $row) {
            $key = $row['repertoire_id'] !== null
                ? 'r' . (int) $row['repertoire_id']
                : self::cleDedup((string) $row['titre'], (string) $row['chant']);
            if (isset($groups[$key])) {
                continue; // déjà pris (ligne plus récente, tri DESC)
            }
            $groups[$key] = [
                'titre'         => $row['titre'],
                'code'          => $row['code'],
                'auteur'        => $row['auteur'],
                'chant'         => $row['chant'],
                'url'           => $row['url'],
                'repertoire_id' => $row['repertoire_id'] !== null ? (int) $row['repertoire_id'] : null,
                'ordinaire'     => $row['ordinaire'],
                'feuille_date'  => $row['feuille_date'],
                'clocher_nom'   => $row['clocher_nom'],
            ];
            if (count($groups) >= $limit) {
                break;
            }
        }

        return array_values($groups);
    }

    /** Date au format court « 12 sept. 2026 » (étiquette de provenance dans la recherche). */
    private static function dateCourte(string $datetime): string
    {
        $dt = new \DateTimeImmutable($datetime);
        $mois = [1 => 'janv.', 2 => 'févr.', 3 => 'mars', 4 => 'avr.', 5 => 'mai', 6 => 'juin',
            7 => 'juil.', 8 => 'août', 9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'déc.'];

        return sprintf('%d %s %d', (int) $dt->format('j'), $mois[(int) $dt->format('n')], (int) $dt->format('Y'));
    }
}
