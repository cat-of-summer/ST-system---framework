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
        static $initialized = null;

        if ($initialized !== null) return;
        $initialized = true;

        Rule::create(function(&$v, array $p): bool {
            if (Rule::isSentinel($v) || $v === null || $v === '') {
                $v = $p[0]::config($p[1]);
            }
            return true;
        })
        ->before(function(&$v, array &$p): void {
            if (count($p) < 2 && ($prefix = Rule::currentPrefix()) !== null) {
                array_unshift($p, $prefix);
            }
        })
        ->seesSentinel()->order(-1)->alias('\\defaultConfig');
    }

    protected static function getDefaultConfig(): array {
        return [];
    }
}
