<?php

declare(strict_types=1);

namespace App\Models;

use App\Database;

final class Token
{
    /**
     * Crée un jeton et renvoie sa valeur en clair (à mettre dans l'URL de l'email).
     * Seul le hash est stocké.
     */
    public static function issue(int $utilisateurId, string $type, string $expiresAt): string
    {
        $raw = bin2hex(random_bytes(32));
        Database::insert('tokens', [
            'utilisateur_id' => $utilisateurId,
            'token_hash'     => hash('sha256', $raw),
            'type'           => $type,
            'expires_at'     => $expiresAt,
        ]);

        return $raw;
    }

    /** Retourne la ligne token + utilisateur si le jeton est valide et non consommé. */
    public static function findValid(string $raw, string $type): ?array
    {
        return Database::one(
            'SELECT t.*, u.email AS user_email, u.paroisse_id AS user_paroisse_id
             FROM tokens t
             JOIN utilisateurs u ON u.id = t.utilisateur_id
             WHERE t.token_hash = ? AND t.type = ? AND t.used_at IS NULL AND t.expires_at > NOW()',
            [hash('sha256', $raw), $type]
        );
    }

    public static function consume(int $id): void
    {
        Database::update('tokens', ['used_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    }

    /** Invalide les jetons en attente d'un utilisateur pour un type donné. */
    public static function invalidatePending(int $utilisateurId, string $type): void
    {
        Database::run(
            'UPDATE tokens SET used_at = NOW() WHERE utilisateur_id = ? AND type = ? AND used_at IS NULL',
            [$utilisateurId, $type]
        );
    }
}
