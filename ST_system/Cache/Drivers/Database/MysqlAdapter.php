<?php

namespace ST_system\Cache\Drivers\Database;

class MysqlAdapter implements DatabaseAdapterInterface {

    private \PDO $pdo;
    private string $table;

    public function __construct(\PDO $pdo, string $table = 'cache_entries') {
        if (!self::isValidIdentifier($table))
            throw new \InvalidArgumentException("Invalid table name: {$table}");
        $this->pdo   = $pdo;
        $this->table = $table;
    }

    public static function isAvailable(): bool {
        return class_exists(\PDO::class) && in_array('mysql', \PDO::getAvailableDrivers(), true);
    }

    /** @return static */
    public static function connect(array $cfg): self {
        $engine = isset($cfg['engine']) ? strtolower((string)$cfg['engine']) : '';
        if ($engine !== '' && $engine !== 'mysql' && $engine !== 'mariadb')
            throw new \RuntimeException("MysqlAdapter does not support engine '{$engine}'");

        $host    = (string)($cfg['host']     ?? '127.0.0.1');
        $port    = (int)   ($cfg['port']     ?? 3306) ?: 3306;
        $dbname  = (string)($cfg['database'] ?? '');
        $charset = (string)($cfg['charset']  ?? 'utf8mb4');
        $table   = (string)($cfg['table']    ?? 'cache_entries');

        if ($dbname === '') throw new \InvalidArgumentException('MysqlAdapter: database is required');

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        $options = ($cfg['options'] ?? []) + [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];

        $pdo = new \PDO($dsn, (string)($cfg['username'] ?? ''), (string)($cfg['password'] ?? ''), $options);

        $self = new static($pdo, $table);

        if (!array_key_exists('auto_migrate', $cfg) || $cfg['auto_migrate'])
            $self->migrate();

        return $self;
    }

    public function write(string $bucket, string $field, string $value): void {
        $sql = "INSERT INTO `{$this->table}` (`bucket`, `field`, `value`) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$bucket, $field, $value]);
    }

    /** @return string|false */
    public function read(string $bucket, string $field) {
        $stmt = $this->pdo->prepare("SELECT `value` FROM `{$this->table}` WHERE `bucket` = ? AND `field` = ? LIMIT 1");
        $stmt->execute([$bucket, $field]);
        $r = $stmt->fetchColumn();
        return ($r === false || $r === null) ? false : (string)$r;
    }

    public function exists(string $bucket, string $field): bool {
        $stmt = $this->pdo->prepare("SELECT 1 FROM `{$this->table}` WHERE `bucket` = ? AND `field` = ? LIMIT 1");
        $stmt->execute([$bucket, $field]);
        return (bool)$stmt->fetchColumn();
    }

    /** @param string|array $buckets */
    public function delete($buckets): void {
        $list = array_values(array_filter((array)$buckets, fn($b) => is_string($b) && $b !== ''));
        if (!$list) return;
        $place = implode(',', array_fill(0, count($list), '?'));
        $stmt  = $this->pdo->prepare("DELETE FROM `{$this->table}` WHERE `bucket` IN ({$place})");
        $stmt->execute($list);
    }

    /**
     * @param int|string|null $cursor
     * @return array|false
     */
    public function scan(&$cursor, string $pattern, int $count) {
        $offset = (int)($cursor ?: 0);
        if ($count <= 0) $count = 100;

        $like = self::globToLike($pattern);

        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT `bucket` FROM `{$this->table}` WHERE `bucket` LIKE ? ESCAPE '\\\\'
             ORDER BY `bucket` LIMIT ? OFFSET ?"
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
        $sql = "CREATE TABLE IF NOT EXISTS `{$this->table}` (
                    `bucket` VARCHAR(190) NOT NULL,
                    `field`  VARCHAR(190) NOT NULL,
                    `value`  LONGTEXT,
                    PRIMARY KEY (`bucket`, `field`),
                    KEY `idx_bucket` (`bucket`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $this->pdo->exec($sql);
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
