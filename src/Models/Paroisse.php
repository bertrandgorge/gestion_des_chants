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

    /**
     * Préférence d'impression de la feuille de chant : « type de section => coché ».
     *
     * @return array<string,bool>
     */
    public static function impressionSections(int $id): array
    {
        $json = Database::value('SELECT impression_sections FROM paroisses WHERE id = ?', [$id]);
        $data = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return is_array($data) ? array_map(static fn ($v) => (bool) $v, $data) : [];
    }

    /** @param array<string,bool> $selection */
    public static function enregistrerImpressionSections(int $id, array $selection): void
    {
        Database::update('paroisses', ['impression_sections' => json_encode($selection)], ['id' => $id]);
    }
}
