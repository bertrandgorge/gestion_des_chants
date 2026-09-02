<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

/**
 * Applique db/schema.sql puis, dans l'ordre alphabétique, db/migrations/*.sql.
 *
 * Idempotent : schema.sql utilise CREATE TABLE IF NOT EXISTS ; les migrations
 * déjà appliquées sont mémorisées dans la table « migrations ».
 *
 * Utilisé par bin/migrate.php (terminal) et par l'assistant d'installation.
 */
final class Migrator
{
    /**
     * @param callable(string):void|null $log  appelé pour chaque fichier appliqué
     * @return list<string>  noms des fichiers réellement appliqués
     */
    public static function run(PDO $pdo, ?string $root = null, ?callable $log = null): array
    {
        $root ??= dirname(__DIR__);
        $log ??= static fn (string $m) => null;
        $applied = [];

        $log('schema.sql');
        self::runFile($pdo, $root . '/db/schema.sql');
        $applied[] = 'schema.sql';

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                fichier VARCHAR(255) NOT NULL,
                applique_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_migrations_fichier (fichier)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $done = $pdo->query('SELECT fichier FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        $dir = $root . '/db/migrations';
        $files = is_dir($dir) ? (glob($dir . '/*.sql') ?: []) : [];
        sort($files);

        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            $log('migration ' . $name);
            self::runFile($pdo, $file);
            $stmt = $pdo->prepare('INSERT INTO migrations (fichier) VALUES (?)');
            $stmt->execute([$name]);
            $applied[] = $name;
        }

        return $applied;
    }

    private static function runFile(PDO $pdo, string $path): void
    {
        $sql = @file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException("Impossible de lire {$path}");
        }
        // PDO::exec supporte plusieurs requêtes séparées par « ; » avec le driver mysqlnd.
        $pdo->exec($sql);
    }
}
