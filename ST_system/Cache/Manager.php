<?php

namespace ST_system\Cache;

use ST_system\Traits\HasConfig;
use ST_system\Cache\CacheDriver;
use ST_system\Cache\Drivers\FileSystemCacheDriver;

final class Manager {

    use HasConfig;

    protected static function getDefaultConfig(): array {
        return [
            'driver' => FileSystemCacheDriver::class,
        ];
    }

    private CacheDriver $driver;

    public function __construct($key, array $config = []) {
        if ($key instanceof CacheDriver) {
            $this->driver = $key;
            return;
        }

        static::hasConfigInit();

        $driver = $config['driver'] ?? static::config('driver');

        if (!class_exists($driver) || !is_subclass_of($driver, CacheDriver::class))
            throw new \InvalidArgumentException("Cache driver must be a subclass of ".CacheDriver::class);

        unset($config['driver']);

        $this->driver = new $driver($key, $config);
    }

    public static function __callStatic(string $name, array $args) {
        switch ($name) {
            case 'make':
            case 'isExpired':
            case 'isValid':
                return static::{$name}(...$args);
        }

        if (!in_array($name, ['get', 'set', 'remember', 'getMeta', 'setMeta'], true))
            throw new \BadMethodCallException("Method {$name} not found");

        $key = array_shift($args);

        $file = null;
        $ttl  = null;

        switch ($name) {
            case 'get':
            case 'getMeta':
                $file = ($args + [null])[0];
                break;
            default:
                [, $file, $ttl] = $args + [null, null, null];
                break;
        }

        $config = [];
        if ($file !== null) $config['file'] = (string)$file;
        if ($ttl  !== null) $config['ttl']  = (int)$ttl;

        return static::make($key, $config)->{$name}(...$args);
    }

    public function __call(string $name, array $args) {
        switch ($name) {
            case 'make':
                $key      = array_shift($args) ?: $this->driver->raw_key;
                $override = (array)array_shift($args);

                return new static($this->driver->spawn($key, $override));

            case 'remember':
                $cb   = $args[0] ?? null;
                $ttl  = $args[1] ?? 0;
                $file = $args[2] ?? '';

                $data = $this->driver->get($file);
                if ($data !== null) return $data;

                $data = $cb();
                $this->driver->set($data, (int)$ttl, (string)$file);
                return $data;

            case 'set':
            case 'get':
            case 'getMeta':
            case 'setMeta':
            case 'isExpired':
            case 'isValid':
            case 'exists':
            case 'purge':
            case 'purgeBase':
                return $this->driver->{$name}(...$args);
        }
        throw new \BadMethodCallException("Method {$name} not found");
    }

    public function __get(string $name) {
        return $this->driver->{$name};
    }

    private static function make(...$args): static { return new static(...$args); }
}
