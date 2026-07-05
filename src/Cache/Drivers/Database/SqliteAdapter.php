<?php

namespace ST_system\Cache\Drivers\Database;

use ST_system\Storage\File;

class SqliteAdapter implements DatabaseAdapterInterface {

    private \PDO $pdo;
    private string $table;

    public function __construct(\PDO $pdo, string $table = 'cache_entries') {
        if (!self::isValidIdentifier($table))
            throw new \InvalidArgumentException("Invalid table name: {$table}");
        $this->pdo   = $pdo;
        $this->table = $table;
    }

    public static function isAvailable(): bool {
        return class_exists(\PDO::class) && in_array('sqlite', \PDO::getAvailableDrivers(), true);
    }

    public static function connect(array $cfg): self {
        $engine = isset($cfg['engine']) ? strtolower((string)$cfg['engine']) : '';
        if (!in_array($engine, ['sqlite', 'sqlite3'], true))
            throw new \RuntimeException("SqliteAdapter does not support engine '{$engine}'");

        $path  = (string)($cfg['database'] ?? '');
        $table = (string)($cfg['table'] ?? 'cache_entries');

        if ($path === '') throw new \InvalidArgumentException('SqliteAdapter: database (file path) is required');

        if ($path === ':memory:') {
            $dsn = 'sqlite::memory:';
        } else {
            // Резолвим путь к файлу БД через Storage\File (поддержка ~/ и относительных путей).
            $real = File::make($path)->getPathname();
            $dir  = dirname($real);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $dsn = 'sqlite:'.$real;
        }

        $pdo = new \PDO($dsn, null, null, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');

        $self = new static($pdo, $table);
        $self->migrate();

        return $self;
    }

    public function write(string $bucket, string $field, string $value): void {
        $sql = "INSERT INTO \"{$this->table}\" (\"bucket\", \"field\", \"value\") VALUES (?, ?, ?)
                ON CONFLICT (\"bucket\", \"field\") DO UPDATE SET \"value\" = excluded.\"value\"";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$bucket, $field, $value]);
    }

    public function read(string $bucket, string $field) {
        $stmt = $this->pdo->prepare("SELECT \"value\" FROM \"{$this->table}\" WHERE \"bucket\" = ? AND \"field\" = ? LIMIT 1");
        $stmt->execute([$bucket, $field]);
        $r = $stmt->fetchColumn();
        return ($r === false || $r === null) ? false : (string)$r;
    }

    public function exists(string $bucket, string $field): bool {
        $stmt = $this->pdo->prepare("SELECT 1 FROM \"{$this->table}\" WHERE \"bucket\" = ? AND \"field\" = ? LIMIT 1");
        $stmt->execute([$bucket, $field]);
        return (bool)$stmt->fetchColumn();
    }

    public function delete($buckets): void {
        $list = array_values(array_filter((array)$buckets, fn($b) => is_string($b) && $b !== ''));
        if (!$list) return;
        $place = implode(',', array_fill(0, count($list), '?'));
        $stmt  = $this->pdo->prepare("DELETE FROM \"{$this->table}\" WHERE \"bucket\" IN ({$place})");
        $stmt->execute($list);
    }

    public function scan(&$cursor, string $pattern, int $count) {
        $offset = (int)($cursor ?: 0);
        if ($count <= 0) $count = 100;

        $like = self::globToLike($pattern);

        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT \"bucket\" FROM \"{$this->table}\" WHERE \"bucket\" LIKE ? ESCAPE '\\'
             ORDER BY \"bucket\" LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $like, \PDO::PARAM_STR);
        $stmt->bindValue(2, $count,  \PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);
        if (!is_array($rows)) $rows = [];

        $cursor = (count($rows) < $count) ? 0 : ($offset + count($rows));
        return $rows ?: false;
    }

    private function migrate(): void {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS \"{$this->table}\" (
                \"bucket\" TEXT NOT NULL,
                \"field\"  TEXT NOT NULL,
                \"value\"  TEXT,
                PRIMARY KEY (\"bucket\", \"field\")
            )"
        );
        $this->pdo->exec(
            "CREATE INDEX IF NOT EXISTS \"{$this->table}_bucket_idx\" ON \"{$this->table}\" (\"bucket\")"
        );
    }

    private static function isValidIdentifier(string $name): bool {
        return (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name);
    }

    private static function globToLike(string $pattern): string {
        $out = '';
        $len = strlen($pattern);
        for ($i = 0; $i < $len; $i++) {
            $ch = $pattern[$i];
            if     ($ch === '*') $out .= '%';
            elseif ($ch === '?') $out .= '_';
            elseif ($ch === '%' || $ch === '_' || $ch === '\\') $out .= '\\'.$ch;
            else $out .= $ch;
        }
        return $out;
    }
}
