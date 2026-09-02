<?php

declare(strict_types=1);

namespace App\Models;

use App\Database;

final class Chant
{
    /** Colonnes éditables d'une section. */
    public const FIELDS = ['titre', 'code', 'auteur', 'chant', 'introduction', 'contenu', 'acclamation', 'reference'];

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
     * @return array<int,array<string,mixed>>
     */
    public static function historique(int $paroisseId, string $q, ?string $type = null): array
    {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $rows = Database::all(
            "SELECT ch.titre, ch.code, ch.auteur, ch.chant, ch.type, ch.feuille_id, f.date_heure
             FROM chants ch
             JOIN feuilles_chant f ON f.id = ch.feuille_id
             JOIN clochers c ON c.id = f.clocher_id
             WHERE c.paroisse_id = ?
               AND f.date_heure < NOW()
               AND ch.chant IS NOT NULL AND ch.chant <> ''
               AND (ch.titre LIKE ? OR ch.code LIKE ?)
             ORDER BY f.date_heure DESC
             LIMIT 300",
            [$paroisseId, $like, $like]
        );

        $groups = [];
        $typesByKey = [];
        foreach ($rows as $row) {
            $key = mb_strtolower(trim((string) $row['titre'])) . '|' . mb_strtolower(trim((string) $row['code']));
            $typesByKey[$key][$row['type']] = true;

            $couplets = count_couplets($row['chant']);
            if (!isset($groups[$key]) || $couplets > $groups[$key]['_couplets']) {
                $groups[$key] = [
                    'titre'      => $row['titre'],
                    'code'       => $row['code'],
                    'auteur'     => $row['auteur'],
                    'chant'      => $row['chant'],
                    'feuille_id' => (int) $row['feuille_id'],
                    '_couplets'  => $couplets,
                ];
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
