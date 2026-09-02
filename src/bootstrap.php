<?php

declare(strict_types=1);

/**
 * Amorçage commun : autoload, configuration, session, base de données.
 * Inclus par public/index.php, bin/*, et les tests.
 */

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/vendor/autoload.php';

// --- Configuration --------------------------------------------------------
$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit("config.php manquant : copiez config.php.example en config.php et renseignez-le.\n");
}

/** @var array $GLOBALS['config'] */
$GLOBALS['config'] = require $configFile;

date_default_timezone_set($GLOBALS['config']['app']['timezone'] ?? 'Europe/Paris');

// --- Erreurs -------------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', '1'); // en production, régler via php.ini / .htaccess

// --- Base de données ----------------------------------------------------
App\Database::configure($GLOBALS['config']['db']);

// --- Session ----------------------------------------------------------
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    // Connexion « illimitée » : la session et son cookie vivent longtemps ;
    // le cookie App\Auth::COOKIE prend le relais si la session est perdue.
    $sessionTtl = 400 * 86400;
    ini_set('session.gc_maxlifetime', (string) $sessionTtl);
    session_set_cookie_params([
        'lifetime' => $sessionTtl,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('gdc_session');
    session_start();
}
