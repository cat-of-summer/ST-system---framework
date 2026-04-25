<?php

namespace ST_system\Traits;

use ST_system\Config;

trait HasConfig {

    final public static function setConfig(array $config = []): void {
        foreach ($config as $key => $value)
            Config::setImmutableConfig(static::class, $key, $value);
    }

    final public static function config(string $key = '') {
        return Config::getImmutableConfig(static::class, $key);
    }
}
