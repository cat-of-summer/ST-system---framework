<?php

namespace ST_system\Cache\Drivers;

use ST_system\Cache\CacheDriver;
use ST_system\Cache\Drivers\Database\DatabaseAdapterInterface;

class DatabaseCacheDriver extends CacheDriver {

    private const ADAPTERS = [
        'mysql'    => \ST_system\Cache\Drivers\Database\MysqlAdapter::class,
        'mariadb'  => \ST_system\Cache\Drivers\Database\MysqlAdapter::class,
        'postgres' => \ST_system\Cache\Drivers\Database\PostgresAdapter::class,
    ];

    private ?DatabaseAdapterInterface $connection = null;

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
        $this->connection           = static::getConnection($config);
        $this->attributes['bucket'] = $this->attributes['prefix'].$this->id;
    }

    protected function __rebind(array $override): void {
        if (isset($override['prefix']) && $override['prefix'] !== null)
            $this->attributes['prefix'] = (string)$override['prefix'];
        if (isset($override['connection']) && $override['connection'] instanceof DatabaseAdapterInterface)
            $this->connection = $override['connection'];
        $this->attributes['bucket'] = $this->attributes['prefix'].$this->id;
    }

    public function isAvailable(): bool {
        return $this->connection !== null;
    }

    private static function getConnection(array $cfg): ?DatabaseAdapterInterface {
        if (isset($cfg['connection']) && $cfg['connection'] instanceof DatabaseAdapterInterface)
            return $cfg['connection'];

        if (!is_string($cfg['engine']) || $cfg['engine'] === '') return null;
        if (!is_string($cfg['host'])   || $cfg['host']   === '') return null;
        if (!is_string($cfg['database']) || $cfg['database'] === '') return null;

        $engine = strtolower($cfg['engine']);
        $adapterClass = self::ADAPTERS[$engine] ?? $cfg['engine'];

        if (!class_exists($adapterClass) || !is_subclass_of($adapterClass, DatabaseAdapterInterface::class))
            return null;
        if (!$adapterClass::isAvailable()) return null;

        static $pool = [];
        $key = md5(serialize([
            $engine,
            (string)($cfg['host']     ?? ''),
            (int)   ($cfg['port']     ?? 0),
            (string)($cfg['database'] ?? ''),
            (string)($cfg['username'] ?? ''),
            (string)($cfg['table']    ?? ''),
        ]));
        if (isset($pool[$key])) return $pool[$key];

        try {
            return $pool[$key] = $adapterClass::connect($cfg);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function writeBlob(string $file, string $payload): void {
        $this->connection->write($this->attributes['bucket'], $file, $payload);
    }

    protected function readBlob(string $file): ?string {
        $r = $this->connection->read($this->attributes['bucket'], $file);
        return ($r === false) ? null : $r;
    }

    protected function writeMeta(string $file, array $meta): void {
        $json = @json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new \RuntimeException('Failed to encode JSON for Database meta');
        $this->connection->write($this->attributes['bucket'], $file.'.meta', $json);
    }

    protected function readMeta(string $file): ?array {
        $r = $this->connection->read($this->attributes['bucket'], $file.'.meta');
        if ($r === false) return null;
        $d = @json_decode($r, true);
        return is_array($d) ? $d : null;
    }

    protected function blobExists(string $file): bool {
        return $this->connection->exists($this->attributes['bucket'], $file);
    }

    protected function purgeStorage(): void {
        $this->connection->delete($this->attributes['bucket']);
    }

    protected function purgeBaseStorage(): void {
        $cursor = 0;
        do {
            $keys = $this->connection->scan($cursor, $this->attributes['prefix'].'*', 500);
            if (is_array($keys) && $keys) $this->connection->delete($keys);
        } while ($cursor !== 0);
    }
}
