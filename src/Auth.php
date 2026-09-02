<?php

declare(strict_types=1);

namespace App;

use App\Models\Connexion;
use App\Models\Utilisateur;

/**
 * Authentification.
 *
 * - Aucun mot de passe : la connexion se fait uniquement via un lien magique
 *   reçu par email (voir AuthController).
 * - Une fois connecté, l'utilisateur reste connecté indéfiniment grâce à un
 *   cookie de connexion persistant (table « connexions »). Il n'est déconnecté
 *   que s'il clique explicitement sur « Se déconnecter ».
 */
final class Auth
{
    /** Nom du cookie de connexion persistant. */
    public const COOKIE = 'gdc_connexion';

    /**
     * Durée de vie du cookie, en secondes (~400 jours : plafond appliqué par
     * les navigateurs). Le cookie est réémis à chaque visite, la connexion est
     * donc de fait illimitée tant que l'utilisateur revient dans l'intervalle.
     */
    private const COOKIE_TTL = 400 * 86400;

    private static ?array $user = null;

    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        self::$user = null;
        Utilisateur::markConnected($userId);
        self::issueCookie($userId);
    }

    public static function logout(): void
    {
        self::forgetCookie();

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
            $id = self::userIdFromCookie();
            if ($id === null) {
                return null;
            }
            $_SESSION['user_id'] = $id;
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

    // --- Cookie de connexion persistant ----------------------------------

    /**
     * Valide le cookie de connexion et renvoie l'id utilisateur associé.
     * Réémet le cookie (glissement de l'expiration) à chaque succès.
     */
    private static function userIdFromCookie(): ?int
    {
        $raw = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($raw) || !str_contains($raw, ':')) {
            return null;
        }

        [$selecteur, $validateur] = explode(':', $raw, 2);
        $row = Connexion::findBySelecteur($selecteur);
        if ($row === null || !hash_equals($row['validateur_hash'], hash('sha256', $validateur))) {
            if ($row !== null) {
                Connexion::deleteBySelecteur($selecteur);
            }
            self::clearCookie();

            return null;
        }

        Connexion::touch($selecteur);
        self::writeCookie($raw);

        return (int) $row['utilisateur_id'];
    }

    private static function issueCookie(int $userId): void
    {
        // Repart d'un cookie propre : on révoque l'éventuel ancien.
        self::forgetCookie();
        self::writeCookie(Connexion::create($userId));
    }

    private static function forgetCookie(): void
    {
        $raw = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($raw) && str_contains($raw, ':')) {
            Connexion::deleteBySelecteur(explode(':', $raw, 2)[0]);
        }
        self::clearCookie();
    }

    private static function writeCookie(string $value): void
    {
        $_COOKIE[self::COOKIE] = $value;
        setcookie(self::COOKIE, $value, self::cookieOptions(time() + self::COOKIE_TTL));
    }

    private static function clearCookie(): void
    {
        unset($_COOKIE[self::COOKIE]);
        setcookie(self::COOKIE, '', self::cookieOptions(time() - 42000));
    }

    /** @return array<string,mixed> */
    private static function cookieOptions(int $expires): array
    {
        return [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => self::secureCookies(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private static function secureCookies(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off') {
            return true;
        }

        return str_starts_with((string) config('app.base_url', ''), 'https://');
    }
}
