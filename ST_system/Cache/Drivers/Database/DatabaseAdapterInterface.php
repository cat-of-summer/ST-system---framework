<?php

namespace ST_system\Cache\Drivers\Database;

interface DatabaseAdapterInterface {
    public static function isAvailable(): bool;
    
    public static function connect(array $cfg): self;

    public function write(string $bucket, string $field, string $value): void;
    
    public function read(string $bucket, string $field);
    public function exists(string $bucket, string $field): bool;
    
    public function delete($buckets): void;
    
    public function scan(&$cursor, string $pattern, int $count);
}
