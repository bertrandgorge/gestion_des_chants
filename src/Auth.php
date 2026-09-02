<?php

declare(strict_types=1);

namespace App;

use App\Models\Utilisateur;

/**
 * Authentification par session.
 */
final class Auth
{
    private static ?array $user = null;

    public static function attempt(string $email, string $password): bool
    {
        $user = Utilisateur::findByEmail($email);
        if ($user === null || empty($user['pass_hash'])) {
            return false;
        }
        if (!password_verify($password, $user['pass_hash'])) {
            return false;
        }

        self::login((int) $user['id']);

        return true;
    }

    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        self::$user = null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        self::$user = null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $id = $_SESSION['user_id'] ?? null;
        if ($id === null) {
            return null;
        }
        $user = Utilisateur::find((int) $id);
        if ($user === null) {
            self::logout();

            return null;
        }

        return self::$user = $user;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user ? (int) $user['id'] : null;
    }

    public static function paroisseId(): ?int
    {
        $user = self::user();

        return $user ? (int) $user['paroisse_id'] : null;
    }

    public static function isAdmin(): bool
    {
        $user = self::user();

        return $user !== null && $user['type'] === 'admin';
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            flash('info', 'Merci de vous connecter.');
            redirect('/login');
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            http_response_code(403);
            exit('Accès réservé aux administrateurs de la paroisse.');
        }
    }
}
