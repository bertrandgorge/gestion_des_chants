<?php

/**
 * Applique db/schema.sql puis, dans l'ordre alphabétique, db/migrations/*.sql.
 *
 * Idempotent : schema.sql utilise CREATE TABLE IF NOT EXISTS ; les migrations
 * déjà appliquées sont mémorisées dans la table « migrations ».
 *
 * Utilisation : php bin/migrate.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$config = require $root . '/config.php';
$db = $config['db'];

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $db['host'], $db['name'], $db['charset'] ?? 'utf8mb4');
$pdo = new PDO($dsn, $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES   => true,
]);

App\Migrator::run($pdo, $root, static fn (string $m) => print("→ {$m}\n"));

echo "Migrations terminées.\n";
