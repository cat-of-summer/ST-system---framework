<?php

namespace ST_system\Cache\Drivers\Redis;

interface RedisAdapterInterface {
    public static function connect(array $cfg): static;
    public function hSet(string $key, string $field, string $value): void;
    public function hGet(string $key, string $field): string|false;
    public function hExists(string $key, string $field): bool;
    public function del(string|array $keys): void;
    public function scan(mixed &$cursor, string $pattern, int $count): array|false;
}
