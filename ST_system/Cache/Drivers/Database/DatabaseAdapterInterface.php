<?php

namespace ST_system\Cache\Drivers\Database;

interface DatabaseAdapterInterface {
    public static function isAvailable(): bool;
    /** @return static */
    public static function connect(array $cfg): self;

    public function write(string $bucket, string $field, string $value): void;
    /** @return string|false */
    public function read(string $bucket, string $field);
    public function exists(string $bucket, string $field): bool;
    /** @param string|array $buckets */
    public function delete($buckets): void;
    /**
     * @param int|string|null $cursor
     * @return array|false
     */
    public function scan(&$cursor, string $pattern, int $count);
}
