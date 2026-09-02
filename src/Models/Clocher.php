<?php

declare(strict_types=1);

namespace App\Models;

use App\Database;
use DateTimeImmutable;

final class Clocher
{
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM clochers WHERE id = ?', [$id]);
    }

    /** Clocher appartenant à la paroisse (sécurité multi-tenant). */
    public static function findForParoisse(int $id, int $paroisseId): ?array
    {
        return Database::one(
            'SELECT * FROM clochers WHERE id = ? AND paroisse_id = ?',
            [$id, $paroisseId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function forParoisse(int $paroisseId): array
    {
        return Database::all(
            'SELECT * FROM clochers WHERE paroisse_id = ? ORDER BY nom',
            [$paroisseId]
        );
    }

    public static function findBySlugs(string $paroisseSlug, string $clocherSlug): ?array
    {
        return Database::one(
            'SELECT c.* FROM clochers c
             JOIN paroisses p ON p.id = c.paroisse_id
             WHERE p.slug = ? AND c.slug = ?',
            [$paroisseSlug, $clocherSlug]
        );
    }

    public static function slugExists(int $paroisseId, string $slug, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM clochers WHERE paroisse_id = ? AND slug = ?';
        $params = [$paroisseId, $slug];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        return (int) Database::value($sql, $params) > 0;
    }

    public static function create(array $data): int
    {
        return Database::insert('clochers', $data);
    }

    public static function update(int $id, array $data): void
    {
        Database::update('clochers', $data, ['id' => $id]);
    }

    public static function hasFeuilles(int $id): bool
    {
        return (int) Database::value('SELECT COUNT(*) FROM feuilles_chant WHERE clocher_id = ?', [$id]) > 0;
    }

    public static function delete(int $id): void
    {
        Database::delete('clochers', ['id' => $id]);
    }

    /**
     * Prochaine occurrence (>= maintenant) du jour/heure par défaut du clocher.
     * Retourne « demain 18:00 » par défaut si le clocher n'a pas de valeur.
     */
    public static function prochaineDateParDefaut(array $clocher, ?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $now = $now ?? new DateTimeImmutable('now');
        $jour = $clocher['jour_defaut'] !== null ? (int) $clocher['jour_defaut'] : null;
        $heure = $clocher['heure_defaut'] ?? null;

        if ($jour === null || $heure === null) {
            return $now->modify('+1 day')->setTime(18, 0);
        }

        [$h, $m] = array_map('intval', explode(':', (string) $heure));
        $candidate = $now->setTime($h, $m);
        // ISO-8601 : N = 1 (lundi) .. 7 (dimanche)
        $delta = ($jour - (int) $candidate->format('N') + 7) % 7;
        $candidate = $candidate->modify("+{$delta} day");
        if ($candidate <= $now) {
            $candidate = $candidate->modify('+7 day');
        }

        return $candidate;
    }
}
