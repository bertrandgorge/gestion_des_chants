<?php

declare(strict_types=1);

/**
 * Fonctions utilitaires globales (chargées via l'autoload Composer).
 */

if (!function_exists('config')) {
    /** Accès à la configuration via une clé « pointée » : config('smtp.host'). */
    function config(string $key, mixed $default = null): mixed
    {
        $value = $GLOBALS['config'] ?? [];
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}

if (!function_exists('base_url')) {
    function base_url(string $path = ''): string
    {
        $base = rtrim((string) config('app.base_url', ''), '/');

        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('e')) {
    /** Échappement HTML. */
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('redirect')) {
    function redirect(string $path): never
    {
        $location = preg_match('#^https?://#', $path) ? $path : '/' . ltrim($path, '/');
        header('Location: ' . $location);
        exit;
    }
}

if (!function_exists('view')) {
    /**
     * Rend une vue PHP de views/ et renvoie le HTML.
     *
     * @param array<string,mixed> $data
     */
    function view(string $name, array $data = []): string
    {
        $file = APP_ROOT . '/views/' . $name . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Vue introuvable : {$name}");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $file;

        return (string) ob_get_clean();
    }
}

if (!function_exists('render')) {
    /** Rend une vue enveloppée dans un layout (variable $content). */
    function render(string $layout, string $name, array $data = []): void
    {
        $content = view($name, $data);
        echo view('layout/' . $layout, array_merge($data, ['content' => $content]));
    }
}

if (!function_exists('json_response')) {
    function json_response(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('input')) {
    /** Valeur POST nettoyée (trim). */
    function input(string $key, ?string $default = null): ?string
    {
        $value = $_POST[$key] ?? $default;

        return is_string($value) ? trim($value) : $default;
    }
}

if (!function_exists('slugify')) {
    function slugify(string $text): string
    {
        $text = (string) preg_replace('~[^\pL\d]+~u', '-', $text);
        if (function_exists('transliterator_transliterate')) {
            $text = (string) transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
        } else {
            $text = strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text));
        }
        $text = (string) preg_replace('~[^-a-z0-9]+~', '', $text);
        $text = trim($text, '-');
        $text = (string) preg_replace('~-+~', '-', $text);

        return $text === '' ? 'x' : $text;
    }
}

if (!function_exists('flash')) {
    /** Écrit (2 args) ou lit-et-efface (1 arg) un message flash de session. */
    function flash(string $key, ?string $message = null): ?string
    {
        if ($message !== null) {
            $_SESSION['_flash'][$key] = $message;

            return null;
        }
        $value = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);

        return $value;
    }
}

if (!function_exists('old')) {
    /** Repopulation de formulaire après erreur. */
    function old(string $key, string $default = ''): string
    {
        return (string) ($_SESSION['_old'][$key] ?? $default);
    }
}

if (!function_exists('remember_old')) {
    function remember_old(array $data): void
    {
        $_SESSION['_old'] = $data;
    }
}

if (!function_exists('clear_old')) {
    function clear_old(): void
    {
        unset($_SESSION['_old']);
    }
}

if (!function_exists('render_chant')) {
    /**
     * Convertit le texte libre d'un chant en HTML.
     *
     * - Les parties (couplets / refrains) sont séparées par deux retours chariot.
     * - Une partie commençant par « R/ » ou « R. » est un refrain : mise en gras.
     * - Les retours simples deviennent des <br>.
     */
    function render_chant(?string $texte): string
    {
        $texte = trim((string) str_replace("\r\n", "\n", (string) $texte));
        if ($texte === '') {
            return '';
        }

        $parts = preg_split('/\n{2,}/', $texte) ?: [];
        $html = '';
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $isRefrain = (bool) preg_match('/^\s*R\s*[\/.]/u', $part);
            $lines = array_map('e', explode("\n", $part));
            $body = implode('<br>', $lines);
            $class = 'chant-partie' . ($isRefrain ? ' chant-refrain' : '');
            $html .= $isRefrain
                ? "<div class=\"{$class}\"><strong>{$body}</strong></div>\n"
                : "<div class=\"{$class}\">{$body}</div>\n";
        }

        return $html;
    }
}

if (!function_exists('strip_guillemets')) {
    /**
     * Retire les guillemets et espaces (y compris insécables) en début/fin de chaîne.
     * Utilise une regex Unicode : un trim() classique casserait les caractères multi-octets.
     */
    function strip_guillemets(?string $s): string
    {
        return (string) preg_replace('/^[\s«»"\x{00A0}]+|[\s«»"\x{00A0}]+$/u', '', (string) $s);
    }
}

if (!function_exists('clean_html')) {
    /**
     * Nettoyage léger d'un fragment HTML (contenu de lecture AELF, éditable par les chantres) :
     * on retire les balises script/style et les attributs d'événement « on... ».
     */
    function clean_html(?string $html): string
    {
        $html = (string) $html;
        if ($html === '') {
            return '';
        }
        $html = preg_replace('#<(script|style|iframe|object|embed)[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? $html;
        $html = preg_replace('#(href|src)\s*=\s*("javascript:[^"]*"|\'javascript:[^\']*\')#i', '', $html) ?? $html;

        return $html;
    }
}

if (!function_exists('count_couplets')) {
    /** Nombre de parties (couplets + refrains) d'un texte de chant. */
    function count_couplets(?string $texte): int
    {
        $texte = trim((string) str_replace("\r\n", "\n", (string) $texte));
        if ($texte === '') {
            return 0;
        }

        return count(array_filter(
            preg_split('/\n{2,}/', $texte) ?: [],
            static fn ($p) => trim($p) !== ''
        ));
    }
}

if (!function_exists('datetime_slug')) {
    /** Représentation d'une date-heure dans une URL de feuille : 2026-09-06-1830. */
    function datetime_slug(string $datetime): string
    {
        return (new DateTimeImmutable($datetime))->format('Y-m-d-Hi');
    }
}

if (!function_exists('parse_datetime_slug')) {
    /** Analyse le segment date-heure d'une URL paroissien. Accepte -1830, -18h30, T18:30. */
    function parse_datetime_slug(string $slug): ?DateTimeImmutable
    {
        $slug = str_replace(['h', 'H', 'T', ' '], [':', ':', '-', '-'], trim($slug));
        // formats : 2026-09-06-18:30  |  2026-09-06-1830  |  2026-09-06
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:-(\d{2}):?(\d{2}))?$/', $slug, $m)) {
            $time = isset($m[2]) ? sprintf('%s:%s:00', $m[2], $m[3]) : '00:00:00';
            try {
                return new DateTimeImmutable($m[1] . ' ' . $time);
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return new DateTimeImmutable($slug);
        } catch (\Throwable) {
            return null;
        }
    }
}

if (!function_exists('feuille_public_url')) {
    function feuille_public_url(array $feuille): string
    {
        return '/' . $feuille['paroisse_slug'] . '/' . $feuille['clocher_slug'] . '/' . datetime_slug($feuille['date_heure']);
    }
}

if (!function_exists('liturgie_couleur_classe')) {
    /**
     * Classe CSS du badge correspondant à une couleur liturgique AELF
     * (noir, rouge, violet, rose, vert, blanc). Chaîne vide si inconnue.
     */
    function liturgie_couleur_classe(?string $couleur): string
    {
        $slug = mb_strtolower(trim((string) $couleur));
        $connues = ['noir', 'rouge', 'violet', 'rose', 'vert', 'blanc'];

        return in_array($slug, $connues, true)
            ? 'badge-liturgie badge-liturgie--' . $slug
            : '';
    }
}

if (!function_exists('jours_semaine')) {
    /** @return array<int,string> 1 => lundi ... 7 => dimanche */
    function jours_semaine(): array
    {
        return [
            1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi',
            5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche',
        ];
    }
}

if (!function_exists('format_date_fr')) {
    function format_date_fr(string $datetime, bool $withTime = true): string
    {
        $dt = new DateTimeImmutable($datetime);
        $jours = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];
        $mois = [1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin',
            7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre'];
        $s = sprintf('%s %d %s %d', $jours[(int) $dt->format('N')], (int) $dt->format('j'), $mois[(int) $dt->format('n')], (int) $dt->format('Y'));
        if ($withTime) {
            $s .= ' à ' . $dt->format('H\hi');
        }

        return $s;
    }
}
