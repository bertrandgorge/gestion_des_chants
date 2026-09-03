<?php

declare(strict_types=1);

namespace App\Models;

use App\Database;

final class Chant
{
    /** Colonnes recopiées d'une section à l'autre (duplication de feuille). */
    public const FIELDS = ['titre', 'code', 'auteur', 'chant', 'nb_couplets', 'introduction', 'contenu', 'acclamation', 'reference', 'url'];

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
     * Recherche dans l'historique des chants d'une paroisse (feuilles passées).
     * Regroupe par (titre, code) et conserve la version avec le plus de couplets.
     *
     * Sources : les autres feuilles de la paroisse (prioritaires) et les chants
     * de catalogue importés (feuille_id NULL, url renseignée). La feuille en cours
     * d'édition est exclue via $excludeFeuilleId. Le champ « url » n'est exposé
     * qu'ici, pour l'interface chantre.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function historique(int $paroisseId, string $q, ?string $type = null, ?int $excludeFeuilleId = null): array
    {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';

        $historique = Database::all(
            "SELECT ch.titre, ch.code, ch.auteur, ch.chant, ch.nb_couplets, ch.type, ch.feuille_id
             FROM chants ch
             JOIN feuilles_chant f ON f.id = ch.feuille_id
             JOIN clochers c ON c.id = f.clocher_id
             WHERE c.paroisse_id = ?
               AND f.id <> ?
               AND ch.chant IS NOT NULL AND ch.chant <> ''
               AND (ch.titre LIKE ? OR ch.code LIKE ?)
             ORDER BY f.date_heure DESC
             LIMIT 300",
            [$paroisseId, $excludeFeuilleId ?? 0, $like, $like]
        );

        $catalogue = Database::all(
            "SELECT ch.titre, ch.code, ch.auteur, ch.chant, ch.nb_couplets, ch.type, ch.url
             FROM chants ch
             WHERE ch.feuille_id IS NULL AND ch.url IS NOT NULL
               AND ch.chant IS NOT NULL AND ch.chant <> ''
               AND (ch.titre LIKE ? OR ch.code LIKE ?)
             ORDER BY ch.titre ASC
             LIMIT 300",
            [$like, $like]
        );

        $groups = [];
        $typesByKey = [];
        foreach ([...$historique, ...$catalogue] as $row) {
            $titre = mb_strtolower(trim((string) $row['titre']));
            $code  = mb_strtolower(trim((string) $row['code']));
            // À défaut de code (fréquent pour le catalogue), l'auteur distingue
            // deux chants homonymes.
            $key = $code !== ''
                ? $titre . '|' . $code
                : $titre . '|~' . mb_strtolower(trim((string) $row['auteur']));
            $typesByKey[$key][$row['type']] = true;

            // Version la plus complète : on privilégie le plus de couplets (hors refrain).
            $couplets = isset($row['nb_couplets']) && $row['nb_couplets'] !== null
                ? (int) $row['nb_couplets']
                : count_couplets($row['chant'], false);
            if (!isset($groups[$key]) || $couplets > $groups[$key]['_couplets']) {
                $groups[$key] = [
                    'titre'      => $row['titre'],
                    'code'       => $row['code'],
                    'auteur'     => $row['auteur'],
                    'chant'      => $row['chant'],
                    'feuille_id' => isset($row['feuille_id']) ? (int) $row['feuille_id'] : null,
                    'url'        => $groups[$key]['url'] ?? ($row['url'] ?? null),
                    '_couplets'  => $couplets,
                ];
            } elseif (($row['url'] ?? null) !== null && ($groups[$key]['url'] ?? null) === null) {
                $groups[$key]['url'] = $row['url'];
            }
        }

        $result = [];
        foreach ($groups as $key => $g) {
            $g['types'] = array_keys($typesByKey[$key]);
            unset($g['_couplets']);
            $result[] = $g;
        }

        // Les correspondances du type demandé d'abord.
        if ($type !== null) {
            usort($result, static fn ($a, $b) => (in_array($type, $b['types'], true) <=> in_array($type, $a['types'], true)));
        }

        return array_slice($result, 0, 20);
    }
}
