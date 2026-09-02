<?php

declare(strict_types=1);

namespace App\Models;

use App\Database;

final class Paroisse
{
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM paroisses WHERE id = ?', [$id]);
    }

    public static function findBySlug(string $slug): ?array
    {
        return Database::one('SELECT * FROM paroisses WHERE slug = ?', [$slug]);
    }

    public static function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM paroisses WHERE slug = ?';
        $params = [$slug];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        return (int) Database::value($sql, $params) > 0;
    }

    public static function create(string $nom, string $slug): int
    {
        return Database::insert('paroisses', ['nom' => $nom, 'slug' => $slug]);
    }

    public static function update(int $id, array $data): void
    {
        Database::update('paroisses', $data, ['id' => $id]);
    }
}
