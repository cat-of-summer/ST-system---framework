<?php

namespace ST_system\Cache;

use ST_system\Traits\HasConfig;
use ST_system\Cache\CacheDriver;

final class Manager {

    use HasConfig {
        setConfig as private traitSetConfig;
    }

    protected static function getDefaultConfig(): array {
        $drivers = [
            'filesystem' => \ST_system\Cache\Drivers\FileSystemCacheDriver::class,
            'redis'      => \ST_system\Cache\Drivers\RedisCacheDriver::class,
            'memcached'  => \ST_system\Cache\Drivers\MemcachedCacheDriver::class,
            'database'   => \ST_system\Cache\Drivers\DatabaseCacheDriver::class,
            'session'    => \ST_system\Cache\Drivers\SessionCacheDriver::class,
        ];

        return [
            'drivers' => [
                'default' => $drivers['filesystem'],
                'available' => $drivers
            ]
        ];
    }

    public static function setConfig(array $config = []): void {
        $flat = \ST_system\Main::dotFlatten($config);

        $managerConfig = [];
        $driverConfigs = [];

        foreach ($flat as $key => $value) {
            if (strncmp($key, 'drivers.', 8) === 0) {
                $rest   = substr($key, strlen('drivers.'));
                $dotPos = strpos($rest, '.');

                if ($dotPos !== false) {
                    $driverName = substr($rest, 0, $dotPos);
                    $subKey     = substr($rest, $dotPos + 1);

                    if ($driverName !== 'default' && $driverName !== 'available') {
                        $driverClass = static::config('drivers.available.'.$driverName) ?: $driverName;
                        $driverConfigs[$driverClass][$subKey] = $value;
                        continue;
                    }
                }
            }
            $managerConfig[$key] = $value;
        }

        if ($managerConfig) {
            static::traitSetConfig($managerConfig);
        }

        foreach ($driverConfigs as $driverClass => $cfg) {
            if (class_exists($driverClass)) {
                $driverClass::setConfig($cfg);
            }
        }
    }

    private CacheDriver $driver;

    public function __construct($key, array $config = []) {
        if ($key instanceof CacheDriver) {
            $this->driver = $key;
            return;
        }

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
            
            case 'purge':
                return ($args[0] ?? true) ? $this->driver->purge() : $this->driver->purgeExpired();

            case 'purgeBase':
                return ($args[0] ?? true) ? $this->driver->purgeBase() : $this->driver->purgeExpiredBase();

            default:
                return $this->driver->{$name}(...$args);
        }
    }

    public function __get(string $name) {
        return $this->driver->{$name};
    }

    
    private static function make(...$args): self { return new static(...$args); }
}
