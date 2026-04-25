<?php

namespace ST_system\Traits;

trait HasConfig {
        
    final public static function setConfig(array $config = []): void {
        static::$CONFIG = array_merge(static::$CONFIG, $config);
    }

    final public static function config(string $key = '')
    {
        if ($key == '')
            return static::$CONFIG;
        
        $current = static::$CONFIG;
        foreach (explode('.', $key) as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
                continue;
            }

            if (is_array($current) && ctype_digit((string)$segment) && array_key_exists((int)$segment, $current)) {
                $current = $current[(int)$segment];
                continue;
            }

            return null;
        }

        return $current;
    }
}