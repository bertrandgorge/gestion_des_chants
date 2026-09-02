<?php

declare(strict_types=1);

namespace App;

/**
 * Protection CSRF : un jeton par session, vérifié sur chaque POST.
 */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function check(): void
    {
        $sent = $_POST['_csrf'] ?? '';
        if (!is_string($sent) || !hash_equals(self::token(), $sent)) {
            http_response_code(419);
            exit('Jeton de sécurité invalide ou expiré. Rechargez la page et réessayez.');
        }
    }
}
