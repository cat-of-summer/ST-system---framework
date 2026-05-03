<?php

namespace ST_system\Cache\Drivers;

use ST_system\Cache\CacheDriver;

class RedisCacheDriver extends CacheDriver {

    private \Redis $connection;

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
        $this->connection           = $this->resolveConnection($config);
        $this->attributes['bucket'] = $this->attributes['prefix'].$this->id;
    }

    protected function __rebind(array $override): void {
        if (isset($override['prefix']) && $override['prefix'] !== null)
            $this->attributes['prefix'] = (string)$override['prefix'];
        if (isset($override['connection']) && $override['connection'] instanceof \Redis)
            $this->connection = $override['connection'];
        $this->attributes['bucket'] = $this->attributes['prefix'].$this->id;
    }

    private function resolveConnection(array $cfg): \Redis {
        if ($cfg['connection'] instanceof \Redis) return $cfg['connection'];

        if (is_string($cfg['host']) && $cfg['host'] !== '') {
            $r = new \Redis();
            $r->connect($cfg['host'], (int)$cfg['port']);
            if (!empty($cfg['auth'])) $r->auth($cfg['auth']);
            if ((int)$cfg['db'] !== 0) $r->select((int)$cfg['db']);
            return $r;
        }

        throw new \RuntimeException('No Redis connection available for RedisCacheDriver');
    }

    protected function writeBlob(string $file, string $payload): void {
        $this->connection->hSet($this->attributes['bucket'], $file, $payload);
    }

    protected function readBlob(string $file): ?string {
        $r = $this->connection->hGet($this->attributes['bucket'], $file);
        return ($r === false || $r === null) ? null : (string)$r;
    }

    protected function writeMeta(string $file, array $meta): void {
        $json = @json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new \RuntimeException('Failed to encode JSON for Redis meta');
        $this->connection->hSet($this->attributes['bucket'], $file.'.meta', $json);
    }

    protected function readMeta(string $file): ?array {
        $r = $this->connection->hGet($this->attributes['bucket'], $file.'.meta');
        if ($r === false || $r === null) return null;
        $d = @json_decode((string)$r, true);
        return is_array($d) ? $d : null;
    }

    protected function blobExists(string $file): bool {
        return (bool)$this->connection->hExists($this->attributes['bucket'], $file);
    }

    protected function purgeStorage(): void {
        $this->connection->del($this->attributes['bucket']);
    }

    protected function purgeBaseStorage(): void {
        $cursor = null;
        do {
            $keys = $this->connection->scan($cursor, $this->attributes['prefix'].'*', 500);
            if (is_array($keys) && $keys) $this->connection->del($keys);
        } while ($cursor !== 0 && $cursor !== null);
    }
}
