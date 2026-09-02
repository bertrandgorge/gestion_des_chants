<?php

declare(strict_types=1);

namespace App\Models;

use App\Database;

final class Utilisateur
{
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM utilisateurs WHERE id = ?', [$id]);
    }

    public static function findByEmail(string $email): ?array
    {
        return Database::one('SELECT * FROM utilisateurs WHERE email = ?', [mb_strtolower($email)]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function forParoisse(int $paroisseId): array
    {
        return Database::all(
            'SELECT * FROM utilisateurs WHERE paroisse_id = ? ORDER BY email',
            [$paroisseId]
        );
    }

    public static function create(string $email, ?string $passHash, string $type, int $paroisseId): int
    {
        return Database::insert('utilisateurs', [
            'email'       => mb_strtolower($email),
            'pass_hash'   => $passHash,
            'type'        => $type,
            'paroisse_id' => $paroisseId,
        ]);
    }

    public static function setPassword(int $id, string $passHash): void
    {
        Database::update('utilisateurs', ['pass_hash' => $passHash], ['id' => $id]);
    }

    public static function setType(int $id, string $type): void
    {
        Database::update('utilisateurs', ['type' => $type], ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        Database::delete('utilisateurs', ['id' => $id]);
    }

    public static function countAdmins(int $paroisseId): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM utilisateurs WHERE paroisse_id = ? AND type = 'admin' AND pass_hash IS NOT NULL",
            [$paroisseId]
        );
    }

    public static function authoredFeuilles(int $id): bool
    {
        return (int) Database::value('SELECT COUNT(*) FROM feuilles_chant WHERE chantre_id = ?', [$id]) > 0;
    }
}
