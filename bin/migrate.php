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
$config = require $root . '/config.php';
$db = $config['db'];

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $db['host'], $db['name'], $db['charset'] ?? 'utf8mb4');
$pdo = new PDO($dsn, $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES   => true,
]);

function run_sql_file(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Impossible de lire {$path}");
    }
    // PDO::exec supporte plusieurs requêtes séparées par « ; » avec le driver mysqlnd.
    $pdo->exec($sql);
}

echo "→ schema.sql\n";
run_sql_file($pdo, $root . '/db/schema.sql');

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS migrations (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        fichier VARCHAR(255) NOT NULL,
        applique_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_migrations_fichier (fichier)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$applied = $pdo->query('SELECT fichier FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
$dir = $root . '/db/migrations';
$files = is_dir($dir) ? glob($dir . '/*.sql') : [];
sort($files);

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }
    echo "→ migration {$name}\n";
    run_sql_file($pdo, $file);
    $stmt = $pdo->prepare('INSERT INTO migrations (fichier) VALUES (?)');
    $stmt->execute([$name]);
}

echo "Migrations terminées.\n";
