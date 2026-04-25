<?php

namespace ST_system;

final class Config {

    private static array $cache = [];
    private static string $configPath = ''; 

    public static function init(array $params): void {
        static $inited = false;

        if ($inited) throw new \Exception('');

        static::$configPath = $params['config_path'] ?? '';
        
        $inited = true;
    }

    public static function reload(): void {

    }

    public static function env(string $name, $default = ''): string {
        
    }

    public static function setConfig(string $name, $value): bool {

    }

    public static function config(string $name, $default = '') {

    }

}