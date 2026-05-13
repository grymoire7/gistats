<?php
class DB
{
    private static ?PDO $pdo = null;

    public static function init(array $config): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO('sqlite:' . $config['db_path']);
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA journal_mode = WAL');
        }
        return self::$pdo;
    }

    public static function createSchema(): void
    {
        self::$pdo->exec('
            CREATE TABLE IF NOT EXISTS users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                username      TEXT    NOT NULL UNIQUE,
                password_hash TEXT    NOT NULL,
                created_at    TEXT    NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\'))
            );
            CREATE TABLE IF NOT EXISTS entries (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id          INTEGER NOT NULL REFERENCES users(id),
                occurred_at      TEXT    NOT NULL,
                duration_seconds INTEGER,
                stool_type       INTEGER NOT NULL,
                note             TEXT,
                created_at       TEXT    NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\'))
            );
            CREATE INDEX IF NOT EXISTS idx_entries_user_occurred
                ON entries(user_id, occurred_at DESC);
        ');
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetch(string $sql, array $params = []): ?array
    {
        $result = self::query($sql, $params)->fetch();
        return $result ?: null;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function execute(string $sql, array $params = []): PDOStatement
    {
        return self::query($sql, $params);
    }

    public static function lastInsertId(): string
    {
        return self::$pdo->lastInsertId();
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }
}
