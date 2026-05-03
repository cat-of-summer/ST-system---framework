<?php

namespace ST_system\Cache;

use ST_system\Traits\HasConfig;
use ST_system\Cache\CacheDriver;

final class Manager {

    use HasConfig;

    protected static function getDefaultConfig(): array {
        $drivers = [
            'filesystem' => \ST_system\Cache\Drivers\FileSystemCacheDriver::class,
            'redis'      => \ST_system\Cache\Drivers\RedisCacheDriver::class,
            'database'   => \ST_system\Cache\Drivers\DatabaseCacheDriver::class,
        ];

        return [
            'drivers' => [
                'default' => $drivers['filesystem'],
                'available' => $drivers
            ]
        ];
    }

    private CacheDriver $driver;

    public function __construct($key, array $config = []) {
        if ($key instanceof CacheDriver) {
            $this->driver = $key;
            return;
        }

        static::hasConfigInit();

        $requested = $config['driver'] ?? static::config('drivers.default');
        $default   = static::config('drivers.default');
        unset($config['driver']);

        $primaryClass = static::config('drivers.available.'.$requested) ?: $requested;
        $defaultClass = static::config('drivers.available.'.$default)   ?: $default;

        $this->driver = static::makeDriver($primaryClass, $key, $config);

        if (!$this->driver->isAvailable() && $primaryClass !== $defaultClass)
            $this->driver = static::makeDriver($defaultClass, $key, $config);
    }

    private static function makeDriver(string $class, $key, array $config): CacheDriver {
        if (!class_exists($class) || !is_subclass_of($class, CacheDriver::class))
            throw new \InvalidArgumentException("Cache driver must be a subclass of ".CacheDriver::class);
        return new $class($key, $config);
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

    /** @return static */
    private static function make(...$args): self { return new static(...$args); }
}
