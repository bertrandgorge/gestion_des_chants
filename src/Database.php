<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Accès à la base de données (PDO) et helpers de requête.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static array $config = [];

    public static function configure(array $config): void
    {
        self::$config = $config;
        self::$pdo = null;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = self::$config;
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                $c['host'],
                $c['name'],
                $c['charset'] ?? 'utf8mb4'
            );
            self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // Aligne le fuseau de la session MySQL sur celui de PHP (NOW() cohérent).
            self::$pdo->exec("SET time_zone = '" . (new \DateTimeImmutable())->format('P') . "'");
        }

        return self::$pdo;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return mixed */
    public static function value(string $sql, array $params = [])
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * Insère une ligne et renvoie l'id généré.
     *
     * @param array<string,mixed> $data
     */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $placeholders = array_map(static fn ($c) => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', $placeholders)
        );
        self::run($sql, $data);

        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Met à jour les lignes correspondant à $where (égalités ET).
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     */
    public static function update(string $table, array $data, array $where): int
    {
        $set = implode(', ', array_map(static fn ($c) => "$c = :set_$c", array_keys($data)));
        $cond = implode(' AND ', array_map(static fn ($c) => "$c = :where_$c", array_keys($where)));

        $params = [];
        foreach ($data as $k => $v) {
            $params["set_$k"] = $v;
        }
        foreach ($where as $k => $v) {
            $params["where_$k"] = $v;
        }

        $stmt = self::run("UPDATE $table SET $set WHERE $cond", $params);

        return $stmt->rowCount();
    }

    public static function delete(string $table, array $where): int
    {
        $cond = implode(' AND ', array_map(static fn ($c) => "$c = :$c", array_keys($where)));

        return self::run("DELETE FROM $table WHERE $cond", $where)->rowCount();
    }

    /**
     * Construit une clause WHERE « recherche multi-mots » : chaque mot de $q doit
     * correspondre à au moins un des gabarits de $templates (OR), tous les mots
     * étant requis (AND). Chaque gabarit est un fragment SQL contenant un seul
     * « ? » (ex. "titre LIKE ?", ou une sous-requête EXISTS sur une autre table).
     *
     * @param list<string> $templates
     * @return array{0:string,1:list<string>} [clause SQL, paramètres à passer à all()/one()]
     */
    public static function likeMots(string $q, array $templates): array
    {
        $conditions = [];
        $params = [];
        foreach (preg_split('/\s+/', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $mot) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $mot) . '%';
            $conditions[] = '(' . implode(' OR ', $templates) . ')';
            array_push($params, ...array_fill(0, count($templates), $like));
        }

        return [implode(' AND ', $conditions), $params];
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
