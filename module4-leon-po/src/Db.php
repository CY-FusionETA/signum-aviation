<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOStatement;

/** Thin PDO wrapper — lazy singleton SQLite connection + small helpers. Ported from Starship. */
final class Db
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo === null) {
            $path = cfg('db.path') ?: (STORAGE_ROOT . '/skyledger.sqlite');
            $dir  = dirname($path);
            if (!is_dir($dir)) mkdir($dir, 0770, true);
            self::$pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA busy_timeout = 5000');
        }
        return self::$pdo;
    }

    public static function q(string $sql, array $params = []): PDOStatement
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::q($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function scalar(string $sql, array $params = [])
    {
        $v = self::q($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $ph   = array_map(fn($c) => ':' . $c, $cols);
        $sql  = "INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";
        self::q($sql, $data);
        return (int)self::conn()->lastInsertId();
    }
}
