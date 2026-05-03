<?php

namespace ST_system\Cache\Drivers\Redis;

interface RedisAdapterInterface {
    /** @return static */
    public static function connect(array $cfg): self;
    public function hSet(string $key, string $field, string $value): void;
    /** @return string|false */
    public function hGet(string $key, string $field);
    public function hExists(string $key, string $field): bool;
    /** @param string|array $keys */
    public function del($keys): void;
    /**
     * @param int|string|null $cursor
     * @return array|false
     */
    public function scan(&$cursor, string $pattern, int $count);
}
