<?php

namespace ST_system\Cache\Drivers\Redis;

interface RedisAdapterInterface {
    public static function isAvailable(): bool;
    
    public static function connect(array $cfg): self;
    public function hSet(string $key, string $field, string $value): void;
    
    public function hGet(string $key, string $field);
    public function hExists(string $key, string $field): bool;
    
    public function del($keys): void;
    
    public function scan(&$cursor, string $pattern, int $count);
}
