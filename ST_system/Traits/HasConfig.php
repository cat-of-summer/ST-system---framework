<?php

namespace ST_system\Traits;

use ST_system\Config;

trait HasConfig {

    final public static function setConfig(array $config = []): void {
        foreach ($config as $key => $value)
            Config::setImmutableConfig(static::class, $key, $value);
    }

    final public static function config(string $key = '') {
        if ($key === '')
            return array_merge(static::getDefaultConfig(), Config::getImmutableConfig(static::class));

        if (Config::hasImmutableConfig(static::class, $key))
            return Config::getImmutableConfig(static::class, $key);

        $segments = explode('.', $key);
        $current = static::getDefaultConfig();
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current))
                return null;
            $current = $current[$segment];
        }
        return $current;
    }

    protected static function getDefaultConfig(): array {
        return [];
    }
}
