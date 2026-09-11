<?php

declare(strict_types=1);

namespace App\Models;

use App\Database;

final class FeuilleChant
{
    private const SELECT = 'SELECT f.*, c.nom AS clocher_nom, c.slug AS clocher_slug,
                                   c.ad_hoc AS clocher_ad_hoc,
                                   p.slug AS paroisse_slug, p.nom AS paroisse_nom,
                                   u.email AS chantre_email
                            FROM feuilles_chant f
                            JOIN clochers c ON c.id = f.clocher_id
                            JOIN paroisses p ON p.id = c.paroisse_id
                            JOIN utilisateurs u ON u.id = f.chantre_id';

    public static function find(int $id): ?array
    {
        return Database::one(self::SELECT . ' WHERE f.id = ?', [$id]);
    }

    public static function findForParoisse(int $id, int $paroisseId): ?array
    {
        return Database::one(self::SELECT . ' WHERE f.id = ? AND p.id = ?', [$id, $paroisseId]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function upcomingForParoisse(int $paroisseId): array
    {
        return Database::all(
            self::SELECT . ' WHERE p.id = ? AND f.date_heure >= (NOW() - INTERVAL 6 HOUR)
             ORDER BY f.date_heure ASC',
            [$paroisseId]
        );
    }

    /**
     * Feuilles passées, paginées par curseur (date_heure décroissante).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function pastForParoisse(int $paroisseId, ?string $before, int $limit = 25): array
    {
        $params = [$paroisseId];
        $sql = self::SELECT . ' WHERE p.id = ? AND f.date_heure < (NOW() - INTERVAL 6 HOUR)';
        if ($before !== null && $before !== '') {
            $sql .= ' AND f.date_heure < ?';
            $params[] = $before;
        }
        $sql .= ' ORDER BY f.date_heure DESC LIMIT ' . ($limit + 1);

        return Database::all($sql, $params);
    }

    /** @return array<int,array<string,mixed>> */
    public static function upcomingForClocher(int $clocherId): array
    {
        return Database::all(
            self::SELECT . ' WHERE f.clocher_id = ? AND f.date_heure >= (NOW() - INTERVAL 2 HOUR)
             ORDER BY f.date_heure ASC LIMIT 30',
            [$clocherId]
        );
    }

    /** Feuille du clocher la plus proche de $instant, dans une fenêtre de +/- $minutes. */
    public static function nearestForClocher(int $clocherId, string $instant, int $minutes): ?array
    {
        return Database::one(
            self::SELECT . '
             WHERE f.clocher_id = ?
               AND ABS(TIMESTAMPDIFF(MINUTE, f.date_heure, ?)) <= ?
             ORDER BY ABS(TIMESTAMPDIFF(SECOND, f.date_heure, ?)) ASC
             LIMIT 1',
            [$clocherId, $instant, $minutes, $instant]
        );
    }

    /** Feuille du clocher à une date/heure donnée (tolérance quelques minutes). */
    public static function atForClocher(int $clocherId, string $datetime): ?array
    {
        return Database::one(
            self::SELECT . '
             WHERE f.clocher_id = ?
             ORDER BY ABS(TIMESTAMPDIFF(SECOND, f.date_heure, ?)) ASC
             LIMIT 1',
            [$clocherId, $datetime]
        );
    }

    public static function create(array $data): int
    {
        return Database::insert('feuilles_chant', $data);
    }

    public static function update(int $id, array $data): void
    {
        Database::update('feuilles_chant', $data, ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        Database::delete('feuilles_chant', ['id' => $id]);
    }
}
