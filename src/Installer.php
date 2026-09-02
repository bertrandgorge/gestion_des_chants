<?php

declare(strict_types=1);

namespace App;

use PDO;
use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

/**
 * Assistant d'installation : servi par bootstrap.php tant que config.php n'existe pas.
 *
 * - GET  /install                 → formulaire de configuration
 * - POST /install/tester-bdd      → test de connexion à la base (JSON)
 * - POST /install/tester-email    → envoi d'un email de test (JSON)
 * - POST /install                 → valide, applique le schéma, écrit config.php
 *
 * Dès que config.php existe, bootstrap.php n'appelle plus cette classe : l'URL
 * /install retombe alors sur une 404 normale.
 */
final class Installer
{
    public static function configPath(): string
    {
        return APP_ROOT . '/config.php';
    }

    public static function run(): never
    {
        // Garde-fou : l'assistant ne fonctionne que sur une installation vierge.
        if (is_file(self::configPath())) {
            self::redirect('/');
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_name('gdc_session');
            session_start();
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = rtrim($path, '/') ?: '/';

        // Tant que l'installation n'est pas terminée, tout mène à l'assistant.
        if ($path !== '/install' && !str_starts_with($path, '/install/')) {
            self::redirect('/install');
        }

        if ($method === 'POST') {
            Csrf::check();

            if ($path === '/install/tester-bdd') {
                $v = self::collect();
                $errs = self::validateDb($v);
                json_response($errs !== []
                    ? ['ok' => false, 'message' => implode(' ', $errs)]
                    : self::testDb($v));
            }

            if ($path === '/install/tester-email') {
                $v = self::collect();
                $errs = self::validateMail($v, true);
                json_response($errs !== []
                    ? ['ok' => false, 'message' => implode(' ', $errs)]
                    : self::testMail($v));
            }

            if ($path === '/install') {
                self::finalize();
            }

            self::redirect('/install');
        }

        self::showForm();
    }

    // --- Étapes ----------------------------------------------------------

    private static function finalize(): never
    {
        $v = self::collect();
        $errors = array_merge(
            self::validateDb($v),
            self::validateMail($v, false),
            self::validateApp($v),
        );
        if ($errors !== []) {
            self::showForm($v, $errors);
        }

        $db = self::testDb($v);
        if (!$db['ok']) {
            self::showForm($v, ['Connexion à la base impossible : ' . $db['message']]);
        }

        // Le schéma est appliqué AVANT l'écriture de config.php : si les
        // migrations échouent, l'assistant reste accessible pour corriger.
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $v['db_host'], $v['db_name']),
                $v['db_user'],
                $v['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true],
            );
            Migrator::run($pdo, APP_ROOT);
        } catch (Throwable $e) {
            error_log('[gdc][install] migrations : ' . $e);
            self::showForm($v, ["Échec de l'initialisation de la base : " . $e->getMessage()]);
        }

        if (!self::writeConfig($v)) {
            // Pas de droits d'écriture : on affiche le contenu à créer à la main.
            self::showManualConfig($v);
        }

        // config.php existe désormais : l'assistant se verrouille de lui-même.
        self::redirect('/register');
    }

    /**
     * Écriture de config.php impossible : la base est prête, on montre le
     * contenu exact du fichier à déposer à la main.
     */
    private static function showManualConfig(array $v): never
    {
        http_response_code(200);
        echo view('install/manuel', [
            'contenu' => self::renderConfig($v),
            'chemin'  => self::configPath(),
        ]);
        exit;
    }

    private static function writeConfig(array $v): bool
    {
        $php = self::renderConfig($v);
        $target = self::configPath();
        $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($tmp, $php, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0640);

        if (!@rename($tmp, $target)) {
            @unlink($tmp);

            return false;
        }

        // Vérifie que le fichier produit est du PHP valide renvoyant un tableau.
        $check = @include $target;
        if (!is_array($check) || !isset($check['db'], $check['smtp'], $check['app'])) {
            @unlink($target);

            return false;
        }

        return true;
    }

    // --- Tests ---------------------------------------------------------

    /** @return array{ok:bool,message:string} */
    private static function testDb(array $v): array
    {
        $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5];
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $v['db_host'], $v['db_name']),
                $v['db_user'],
                $v['db_pass'],
                $opts,
            );
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

            return ['ok' => true, 'message' => "Connexion réussie (MySQL {$version})."];
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'Unknown database')) {
                try {
                    new PDO(sprintf('mysql:host=%s', $v['db_host']), $v['db_user'], $v['db_pass'], $opts);

                    return [
                        'ok' => false,
                        'message' => "Le serveur MySQL répond, mais la base « {$v['db_name']} » n'existe pas. "
                            . 'Créez-la (cPanel / phpMyAdmin) puis réessayez.',
                    ];
                } catch (Throwable $inner) {
                    return ['ok' => false, 'message' => $inner->getMessage()];
                }
            }

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array{ok:bool,message:string} */
    private static function testMail(array $v): array
    {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $v['smtp_host'];
            $mail->Port = (int) $v['smtp_port'];
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 12;

            if ($v['smtp_user'] !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $v['smtp_user'];
                $mail->Password = $v['smtp_pass'];
            } else {
                $mail->SMTPAuth = false;
            }

            if ($v['smtp_secure'] === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($v['smtp_secure'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            $from = $v['smtp_from'] !== '' ? $v['smtp_from'] : $v['smtp_user'];
            $mail->setFrom($from, $v['smtp_from_name'] !== '' ? $v['smtp_from_name'] : 'Feuilles de messe');
            $mail->addAddress($v['test_email']);
            $mail->isHTML(true);
            $mail->Subject = 'Test de configuration — Feuilles de messe';
            $mail->Body = '<p>Cet email confirme que la configuration SMTP fonctionne.</p>';
            $mail->AltBody = 'Cet email confirme que la configuration SMTP fonctionne.';
            $mail->send();

            return [
                'ok' => true,
                'message' => "Email envoyé à {$v['test_email']}. Vérifiez la boîte de réception (et les indésirables).",
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage()];
        }
    }

    // --- Validation --------------------------------------------------

    /** @return list<string> */
    private static function validateDb(array $v): array
    {
        $errors = [];
        if ($v['db_host'] === '') {
            $errors[] = "L'hôte de la base est obligatoire.";
        }
        if ($v['db_name'] === '') {
            $errors[] = 'Le nom de la base est obligatoire.';
        }
        if ($v['db_user'] === '') {
            $errors[] = "L'utilisateur de la base est obligatoire.";
        }

        return $errors;
    }

    /** @return list<string> */
    private static function validateMail(array $v, bool $withTestEmail): array
    {
        $errors = [];
        if ($v['smtp_host'] === '') {
            $errors[] = 'Le serveur SMTP est obligatoire.';
        }
        if ((int) $v['smtp_port'] <= 0) {
            $errors[] = 'Le port SMTP est invalide.';
        }
        if ($v['smtp_from'] !== '' && !filter_var($v['smtp_from'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "L'adresse d'expéditeur est invalide.";
        }
        if (!in_array($v['smtp_secure'], ['tls', 'ssl', ''], true)) {
            $errors[] = 'Le chiffrement SMTP est invalide.';
        }
        if ($withTestEmail && !filter_var($v['test_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Indiquez une adresse de destination valide pour le test.';
        }

        return $errors;
    }

    /** @return list<string> */
    private static function validateApp(array $v): array
    {
        if (!filter_var($v['app_base_url'], FILTER_VALIDATE_URL)) {
            return ["L'URL publique de l'application est invalide (ex. https://paroisse.fr)."];
        }

        return [];
    }

    // --- Entrées / sorties -------------------------------------------

    /** @return array<string,string> */
    private static function collect(): array
    {
        $out = [];
        foreach (self::defaults() as $key => $default) {
            $value = $_POST[$key] ?? $default;
            $out[$key] = is_string($value) ? trim($value) : $default;
        }
        $out['app_base_url'] = rtrim($out['app_base_url'], '/');

        return $out;
    }

    /** @return array<string,string> */
    private static function defaults(): array
    {
        return [
            'db_host'        => 'localhost',
            'db_name'        => '',
            'db_user'        => '',
            'db_pass'        => '',
            'smtp_host'      => '',
            'smtp_port'      => '587',
            'smtp_user'      => '',
            'smtp_pass'      => '',
            'smtp_secure'    => 'tls',
            'smtp_from'      => '',
            'smtp_from_name' => 'Feuilles de messe',
            'app_base_url'   => self::guessBaseUrl(),
            'test_email'     => '',
        ];
    }

    private static function guessBaseUrl(): string
    {
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? '') === '443'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return ($https ? 'https' : 'http') . '://' . $host;
    }

    /**
     * @param array<string,string> $values
     * @param list<string>         $errors
     */
    private static function showForm(?array $values = null, array $errors = []): never
    {
        $values ??= self::defaults();
        http_response_code($errors === [] ? 200 : 422);
        echo view('install/index', [
            'values'   => $values,
            'errors'   => $errors,
            'csrf'     => Csrf::field(),
            'writable' => is_writable(APP_ROOT),
        ]);
        exit;
    }

    private static function renderConfig(array $v): string
    {
        $dbHost   = var_export($v['db_host'], true);
        $dbName   = var_export($v['db_name'], true);
        $dbUser   = var_export($v['db_user'], true);
        $dbPass   = var_export($v['db_pass'], true);
        $smtpHost = var_export($v['smtp_host'], true);
        $smtpPort = (int) $v['smtp_port'];
        $smtpUser = var_export($v['smtp_user'], true);
        $smtpPass = var_export($v['smtp_pass'], true);
        $smtpSec  = var_export($v['smtp_secure'], true);
        $smtpFrom = var_export($v['smtp_from'] !== '' ? $v['smtp_from'] : $v['smtp_user'], true);
        $smtpName = var_export($v['smtp_from_name'] !== '' ? $v['smtp_from_name'] : 'Feuilles de messe', true);
        $baseUrl  = var_export($v['app_base_url'], true);
        $date     = date('Y-m-d H:i');

        return <<<PHP
        <?php

        /**
         * Configuration de l'application — générée par l'assistant d'installation le {$date}.
         *
         * Ce fichier n'est PAS versionné (voir .gitignore) : il contient les secrets.
         * Pour reconfigurer, supprimez ce fichier : l'assistant /install redeviendra accessible.
         */

        return [
            'db' => [
                'host'    => {$dbHost},
                'name'    => {$dbName},
                'user'    => {$dbUser},
                'pass'    => {$dbPass},
                'charset' => 'utf8mb4',
            ],

            'smtp' => [
                'host'      => {$smtpHost},
                'port'      => {$smtpPort},
                'user'      => {$smtpUser},
                'pass'      => {$smtpPass},
                'secure'    => {$smtpSec},
                'from'      => {$smtpFrom},
                'from_name' => {$smtpName},
            ],

            'app' => [
                // URL publique de l'application, SANS slash final.
                'base_url' => {$baseUrl},

                // Affichage des erreurs PHP dans le navigateur : à laisser sur false.
                'debug' => false,
            ],
        ];

        PHP;
    }

    private static function redirect(string $path): never
    {
        header('Location: ' . $path, true, 303);
        exit;
    }
}
