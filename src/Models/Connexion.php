<?php

declare(strict_types=1);

namespace App\Models;

use App\Database;

/**
 * Connexion persistante : jeton « se souvenir de moi » stocké côté serveur.
 *
 * Le cookie transmis vaut « selecteur:validateur ». Seul le hash du validateur
 * est enregistré ; le sélecteur sert de clé de recherche (comparaison en temps
 * constant du validateur, cf. Auth).
 */
final class Connexion
{
    /** Crée une connexion et renvoie la valeur à placer dans le cookie. */
    public static function create(int $utilisateurId): string
    {
        $selecteur = bin2hex(random_bytes(12));
        $validateur = bin2hex(random_bytes(32));

        Database::insert('connexions', [
            'utilisateur_id'  => $utilisateurId,
            'selecteur'       => $selecteur,
            'validateur_hash' => hash('sha256', $validateur),
            'user_agent'      => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);

        return $selecteur . ':' . $validateur;
    }

    /** @return array<string,mixed>|null */
    public static function findBySelecteur(string $selecteur): ?array
    {
        return Database::one('SELECT * FROM connexions WHERE selecteur = ?', [$selecteur]);
    }

    public static function touch(string $selecteur): void
    {
        Database::update('connexions', ['derniere_utilisation' => date('Y-m-d H:i:s')], ['selecteur' => $selecteur]);
    }

    public static function deleteBySelecteur(string $selecteur): void
    {
        Database::delete('connexions', ['selecteur' => $selecteur]);
    }

    public static function deleteForUser(int $utilisateurId): void
    {
        Database::delete('connexions', ['utilisateur_id' => $utilisateurId]);
    }
}
