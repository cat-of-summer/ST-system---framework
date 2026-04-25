<?php

namespace ST_system\Traits;

use ST_system\Config;
use ST_system\Rule;

trait HasConfig {

    final public static function setConfig(array $config = []): void {
        foreach ($config as $key => $value)
            Config::setImmutableConfig(static::class, $key, $value);
    }

    final public static function config(string $key = '') {
        static $initialized = [];
        if (!isset($initialized[static::class])) {
            Config::fillImmutableConfig(static::class, '', static::getDefaultConfig());
            $initialized[static::class] = true;
        }
        return Config::getImmutableConfig(static::class, $key);
    }

    public static function hasConfigInit(): void {
        static $inited = [];

        if (isset($inited[static::class])) return;

        $inited[static::class] = true;

        // Rule::create(function(&$v, array $p): bool {
        //     $class = $p[0];
        //     $key = $p[1];

        //     return in_array($v, $p, false);

        //     if ($v === self::sentinel() || $v === null || $v === '') {
        //         $v = $value;
        //     }

        //     if 

        //     return true;
        // })->seesSentinel()->order(-1)->alias('defaultConfig');

        // foreach (array_keys(static::config()) as $key)
        //     Rule::default(static::config($key))->alias("defaultConfig:{$key}", 1);
    }

    protected static function getDefaultConfig(): array {
        return [];
    }
}
