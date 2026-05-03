<?php

namespace ST_system\Cache\Drivers;

use ST_system\Cache\CacheDriver;
use ST_system\Cache\Drivers\Redis\RedisAdapterInterface;

class RedisCacheDriver extends CacheDriver {

    private const ADAPTERS = [
        \ST_system\Cache\Drivers\Redis\PhpRedisAdapter::class,
        \ST_system\Cache\Drivers\Redis\PredisAdapter::class,
    ];

    private ?RedisAdapterInterface $connection = null;

    protected static function getDefaultConfig(): array {
        return [
            'file'       => 'data',
            'ttl'        => 3600,
            'prefix'     => 'cache:',
            'host'       => null,
            'port'       => 6379,
            'auth'       => null,
            'db'         => 0,
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
        if (isset($override['connection']) && $override['connection'] instanceof RedisAdapterInterface)
            $this->connection = $override['connection'];
        $this->attributes['bucket'] = $this->attributes['prefix'].$this->id;
    }

    public function isAvailable(): bool {
        return $this->connection !== null;
    }

    private static function getConnection(array $cfg): ?RedisAdapterInterface {
        if (isset($cfg['connection']) && $cfg['connection'] instanceof RedisAdapterInterface)
            return $cfg['connection'];

        if (!is_string($cfg['host']) || $cfg['host'] === '') return null;

        static $pool = [];
        $key = md5(serialize([
            (string)($cfg['host'] ?? ''),
            (int)   ($cfg['port'] ?? 0),
            (string)($cfg['auth'] ?? ''),
            (int)   ($cfg['db']   ?? 0),
        ]));
        if (isset($pool[$key])) return $pool[$key];

        foreach (self::ADAPTERS as $adapterClass) {
            if (!$adapterClass::isAvailable()) continue;
            try {
                return $pool[$key] = $adapterClass::connect($cfg);
            } catch (\Throwable $e) {
                continue;
            }
        }
        return null;
    }

    protected function writeBlob(string $file, string $payload): void {
        $this->connection->hSet($this->attributes['bucket'], $file, $payload);
    }

    protected function readBlob(string $file): ?string {
        $r = $this->connection->hGet($this->attributes['bucket'], $file);
        return ($r === false) ? null : $r;
    }

    protected function writeMeta(string $file, array $meta): void {
        $json = @json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new \RuntimeException('Failed to encode JSON for Redis meta');
        $this->connection->hSet($this->attributes['bucket'], $file.'.meta', $json);
    }

    protected function readMeta(string $file): ?array {
        $r = $this->connection->hGet($this->attributes['bucket'], $file.'.meta');
        if ($r === false) return null;
        $d = @json_decode($r, true);
        return is_array($d) ? $d : null;
    }

    protected function blobExists(string $file): bool {
        return $this->connection->hExists($this->attributes['bucket'], $file);
    }

    protected function purgeStorage(): void {
        $this->connection->del($this->attributes['bucket']);
    }

    protected function purgeBaseStorage(): void {
        $cursor = 0;
        do {
            $keys = $this->connection->scan($cursor, $this->attributes['prefix'].'*', 500);
            if (is_array($keys) && $keys) $this->connection->del($keys);
        } while ($cursor !== 0);
    }
}
