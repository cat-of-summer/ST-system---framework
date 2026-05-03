<?php

namespace ST_system\Cache\Drivers;

use ST_system\Cache\CacheDriver;
use ST_system\Cache\Drivers\Redis\RedisAdapterInterface;

class RedisCacheDriver extends CacheDriver {

    private const CLIENT_MAP = [
        \Redis::class         => \ST_system\Cache\Drivers\Redis\PhpRedisAdapter::class,
        \Relay\Relay::class   => \ST_system\Cache\Drivers\Redis\PhpRedisAdapter::class,
        \Predis\Client::class => \ST_system\Cache\Drivers\Redis\PredisAdapter::class,
    ];

    private RedisAdapterInterface $connection;

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
        if ($override['connection'] instanceof RedisAdapterInterface)
            $this->connection = $override['connection'];
        $this->attributes['bucket'] = $this->attributes['prefix'].$this->id;
    }

    private static function getConnection(array $cfg): RedisAdapterInterface {
        static $resolved_adapter = null;

        if ($cfg['connection'] instanceof RedisAdapterInterface) return $cfg['connection'];

        if (is_string($cfg['host']) && $cfg['host'] !== '') {
            if ($resolved_adapter === null) {
                foreach (self::CLIENT_MAP as $nativeClass => $adapterClass) {
                    if (class_exists($nativeClass)) {
                        $resolved_adapter = $adapterClass;
                        break;
                    }
                }
            }
            if ($resolved_adapter !== null)
                return ($resolved_adapter)::connect($cfg);
        }

        throw new \RuntimeException('No Redis client available for RedisCacheDriver');
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
