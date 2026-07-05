<?php

namespace ST_system\Cache\Drivers\Memcached;

interface MemcachedAdapterInterface {
    public static function isAvailable(): bool;

    public static function connect(array $cfg): self;

    public function get(string $key);
    public function set(string $key, string $value, int $expiry = 0): void;
    public function touch(string $key, int $expiry): void;

    public function delete($keys): void;
    public function exists(string $key): bool;

    public function flush(): void;
}
