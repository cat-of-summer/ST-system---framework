<?php

namespace ST_system\Cache\Drivers;

use ST_system\Cache\CacheDriver;
use ST_system\Cache\Drivers\Database\DatabaseAdapterInterface;

class DatabaseCacheDriver extends CacheDriver {

    private const ADAPTERS = [
        'mysql'    => \ST_system\Cache\Drivers\Database\MysqlAdapter::class,
        'mariadb'  => \ST_system\Cache\Drivers\Database\MysqlAdapter::class,
        'postgres' => \ST_system\Cache\Drivers\Database\PostgresAdapter::class,
        'sqlite'   => \ST_system\Cache\Drivers\Database\SqliteAdapter::class,
        'sqlite3'  => \ST_system\Cache\Drivers\Database\SqliteAdapter::class,
    ];

    private ?DatabaseAdapterInterface $connection = null;

    private array $connection_config = [];

    private bool $connection_injected = false;

    private static array $pool = [];

    private static array $live = [];

    protected static function getDefaultConfig(): array {
        return [
            'file'       => 'data',
            'ttl'        => 3600,
            'prefix'     => 'cache:',
            'engine'     => null,
            'host'       => null,
            'port'       => null,
            'username'   => null,
            'password'   => null,
            'database'   => null,
            'table'      => 'cache',
            'charset'    => 'utf8mb4',
            'connection' => null,
        ];
    }

    protected function __init(array $config): void {
        $this->attributes['prefix'] = (string)$config['prefix'];
        $this->connection_config    = $config;
        $this->connection_injected  = isset($config['connection'])
                                      && $config['connection'] instanceof DatabaseAdapterInterface;
        $this->connection           = static::getConnection($config);
        $this->attributes['bucket'] = $this->attributes['prefix'].$this->id;

        self::$live[] = \WeakReference::create($this);
    }

    public function __clone() {
        self::$live[] = \WeakReference::create($this);
    }

    protected function __rebind(array $override): void {
        if (isset($override['prefix']) && $override['prefix'] !== null)
            $this->attributes['prefix'] = (string)$override['prefix'];
        if (isset($override['connection']) && $override['connection'] instanceof DatabaseAdapterInterface) {
            $this->connection          = $override['connection'];
            $this->connection_injected = true;
        }
        $this->attributes['bucket'] = $this->attributes['prefix'].$this->id;
    }

    public function isAvailable(): bool {
        return $this->connection() !== null;
    }

    private function connection(): ?DatabaseAdapterInterface {
        if ($this->connection === null && !$this->connection_injected && $this->connection_config !== [])
            $this->connection = static::getConnection($this->connection_config);

        return $this->connection;
    }

    public static function disconnect(): void {
        self::$pool = [];

        foreach (self::$live as $index => $ref) {
            $driver = $ref->get();

            if ($driver === null)             { unset(self::$live[$index]); continue; }
            if ($driver->connection_injected) continue;

            $driver->connection = null;
        }

        self::$live = array_values(self::$live);
    }

    private static function getConnection(array $cfg): ?DatabaseAdapterInterface {
        if (isset($cfg['connection']) && $cfg['connection'] instanceof DatabaseAdapterInterface)
            return $cfg['connection'];

        if (!is_string($cfg['engine']) || $cfg['engine'] === '') return null;

        $engine   = strtolower($cfg['engine']);
        $isSqlite = in_array($engine, ['sqlite', 'sqlite3'], true);

        if (!$isSqlite && (!is_string($cfg['host']) || $cfg['host'] === '')) return null;
        if (!is_string($cfg['database']) || $cfg['database'] === '') return null;

        $adapterClass = self::ADAPTERS[$engine] ?? $cfg['engine'];

        if (!class_exists($adapterClass) || !is_subclass_of($adapterClass, DatabaseAdapterInterface::class))
            return null;
        if (!$adapterClass::isAvailable()) return null;

        $key = \ST_system\Main::hash([
            'engine'   => $engine,
            'host'     => (string)($cfg['host']     ?? ''),
            'port'     => (int)   ($cfg['port']     ?? 0),
            'database' => (string)($cfg['database'] ?? ''),
            'username' => (string)($cfg['username'] ?? ''),
            'table'    => (string)($cfg['table']    ?? ''),
        ]);
        if (isset(self::$pool[$key])) return self::$pool[$key];

        try {
            return self::$pool[$key] = $adapterClass::connect($cfg);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function writeBlob(string $file, string $payload): void {
        $this->connection()->write($this->attributes['bucket'], $file, $payload);
    }

    protected function readBlob(string $file): ?string {
        $r = $this->connection()->read($this->attributes['bucket'], $file);
        return ($r === false) ? null : $r;
    }

    protected function writeMeta(string $file, array $meta): void {
        $json = @json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new \RuntimeException('Failed to encode JSON for Database meta');
        $this->connection()->write($this->attributes['bucket'], $file.'.meta', $json);
    }

    protected function readMeta(string $file): ?array {
        $r = $this->connection()->read($this->attributes['bucket'], $file.'.meta');
        if ($r === false) return null;
        $d = @json_decode($r, true);
        return is_array($d) ? $d : null;
    }

    protected function blobExists(string $file): bool {
        return $this->connection()->exists($this->attributes['bucket'], $file);
    }

    protected function purgeStorage(): void {
        $this->connection()->delete($this->attributes['bucket']);
    }

    protected function purgeBaseStorage(): void {
        $cursor = 0;
        do {
            $keys = $this->connection()->scan($cursor, $this->attributes['prefix'].'*', 500);
            if (is_array($keys) && $keys) $this->connection()->delete($keys);
        } while ($cursor !== 0);
    }

    protected function purgeExpiredStorage(): void {
        $meta_field = $this->attributes['file'].'.meta';
        $raw     = $this->connection()->read($this->attributes['bucket'], $meta_field);
        $decoded = is_string($raw) ? @json_decode($raw, true) : null;
        $expires = is_array($decoded) ? ($decoded['expires_in'] ?? 0) : 0;

        if ($expires !== -1 && $expires < time())
            $this->connection()->delete($this->attributes['bucket']);
    }

    protected function purgeExpiredBaseStorage(): void {
        $meta_field = $this->attributes['file'].'.meta';
        $cursor = 0;
        do {
            $keys = $this->connection()->scan($cursor, $this->attributes['prefix'].'*', 500);
            if (!is_array($keys) || !$keys) continue;

            $now = time();
            $to_delete = [];
            foreach ($keys as $bucket) {
                $raw     = $this->connection()->read($bucket, $meta_field);
                $decoded = is_string($raw) ? @json_decode($raw, true) : null;
                $expires = is_array($decoded) ? ($decoded['expires_in'] ?? 0) : 0;
                if ($expires !== -1 && $expires < $now) $to_delete[] = $bucket;
            }

            if ($to_delete) $this->connection()->delete($to_delete);
        } while ($cursor !== 0);
    }
}
