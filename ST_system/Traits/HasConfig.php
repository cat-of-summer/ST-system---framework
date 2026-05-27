<?php

namespace ST_system\Traits;

use ST_system\Config;
use ST_system\Rule;

trait HasConfig {

    public static function setConfig(array $config = []): void {
        foreach ($config as $key => $value)
            Config::setImmutableConfig(static::class, $key, $value);
    }

    public static function config(string $key = '') {
        static $initialized = [];
        if (!isset($initialized[static::class])) {

            if (!empty(Config::config(static::class)))
                Config::fillImmutableConfig(static::class, '', Config::config(static::class));

            Config::fillImmutableConfig(static::class, '', static::getDefaultConfig());
            
            $initialized[static::class] = true;
        }
        return Config::getImmutableConfig(static::class, $key);
    }

    public static function applyConfig(array &$config, array $schema = []): void {
        if (!Rule::get('defaultConfig')) {
            Rule::create(function(&$v, array $p): bool {
                $class = $p[0];

                if (is_array($v) && !isset($p[1])) {
                    foreach ($class::config() as $k => $_) {
                        if (!array_key_exists($k, $v) || $v[$k] === null || $v[$k] === '')
                            $v[$k] = $class::config($k);
                    }
                    return true;
                }

                if (Rule::isSentinel($v) || $v === null || $v === '') {
                    $v = $class::config($p[1] ?? '');
                }

                return true;
            })
            ->before(function(&$v, array &$p): void {
                if (count($p) < 2 && ($prefix = Rule::currentPrefix()) !== null) {
                    array_unshift($p, $prefix);
                }
            })
            ->seesSentinel()->order(-3)->alias('\\defaultConfig');
        }

        if (empty($schema)) {
            Rule::scope(static::class, function() use (&$config) {
                Rule::get('defaultConfig')->apply($config);
            });
            return;
        }

        Rule::scope(static::class, function() use (&$config, $schema) {
            Rule::object(array_map(fn($spec) => static::resolveAtSpec($spec), $schema))->throwable()->apply($config);
        });
    }

    private static function resolveAtSpec($spec) {
        if (is_string($spec))
            return str_replace('@', 'defaultConfig:', $spec);
        if (is_array($spec))
            return array_map(fn($item) => static::resolveAtSpec($item), $spec);
        return $spec;
    }

    protected static function getDefaultConfig(): array {
        return [];
    }
}
