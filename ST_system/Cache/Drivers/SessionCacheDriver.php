<?php

namespace ST_system\Cache\Drivers;

use ST_system\Cache\CacheDriver;

class SessionCacheDriver extends CacheDriver {

    protected static function getDefaultConfig(): array {
        return [
            'file'   => 'data',
            'ttl'    => 0,
            'prefix' => 'st_cache',
        ];
    }

    protected function __init(array $config): void {
        $this->attributes['prefix'] = (string)($config['prefix'] ?? 'st_cache');
    }

    protected function __rebind(array $override): void {
        if (isset($override['prefix']) && $override['prefix'] !== null)
            $this->attributes['prefix'] = (string)$override['prefix'];
    }

    public function isAvailable(): bool {
        if (session_status() === PHP_SESSION_DISABLED) return false;
        if (session_status() === PHP_SESSION_NONE) session_start();
        return session_status() === PHP_SESSION_ACTIVE;
    }

    protected function writeBlob(string $file, string $payload): void {
        $_SESSION[$this->attributes['prefix']][$this->id][$file] = $payload;
    }

    protected function readBlob(string $file): ?string {
        $v = $_SESSION[$this->attributes['prefix']][$this->id][$file] ?? null;
        return is_string($v) ? $v : null;
    }

    protected function writeMeta(string $file, array $meta): void {

        $maxLifetime = (int)ini_get('session.gc_maxlifetime');
        if ($maxLifetime > 0 && isset($meta['expires_in']) && $meta['expires_in'] !== -1) {
            $ttlRequested = $meta['expires_in'] - time();
            if ($ttlRequested > $maxLifetime)
                throw new \RuntimeException(
                    "Session cache TTL ({$ttlRequested}s) exceeds session.gc_maxlifetime ({$maxLifetime}s)"
                );
        }

        $_SESSION[$this->attributes['prefix']][$this->id][$file.'.meta'] = $meta;
    }

    protected function readMeta(string $file): ?array {
        $v = $_SESSION[$this->attributes['prefix']][$this->id][$file.'.meta'] ?? null;
        return is_array($v) ? $v : null;
    }

    protected function blobExists(string $file): bool {
        return isset($_SESSION[$this->attributes['prefix']][$this->id][$file]);
    }

    protected function purgeStorage(): void {
        unset($_SESSION[$this->attributes['prefix']][$this->id]);
    }

    protected function purgeBaseStorage(): void {
        unset($_SESSION[$this->attributes['prefix']]);
    }
}
