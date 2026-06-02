<?php

namespace ST_system;

use ST_system\Main;
use Dotenv\Dotenv;

final class Config {

    private static array $cache = [];
    private static string $configPath = '';

    public static function init(array $params = []): void {
        static $initialized = false;

        if ($initialized)
            throw new \LogicException('Config::init() может быть вызван только один раз.');

        $configPath = $params['config_path'] ?? '';
        $dotenvDir = $params['dotenv_path'] ?? (function() use ($configPath) {
            $configDir = is_file($configPath) ? dirname($configPath) : $configPath;
            if ($configDir !== '' && file_exists($configDir . DIRECTORY_SEPARATOR . '.env'))
                return $configDir;

            $root = static::getDefault()['COMPOSER_ROOT'];
            if ($root !== '' && file_exists($root . DIRECTORY_SEPARATOR . '.env'))
                return $root;

            return null;
        })();

        if ($dotenvDir !== null)
            Dotenv::createImmutable($dotenvDir)->safeLoad();

        static::$configPath = $configPath;
        $initialized = true;
    }

    public static function reload(): void {
        static::$cache[static::envKey()]    = [];
        static::$cache[static::configKey()] = [];
        static::$cache[static::iniKey()]    = [];
    }

    public static function env(string $name, $default = ''): string {
        if (array_key_exists($name, static::$cache[static::envKey()] ?? []))
            return static::$cache[static::envKey()][$name];

        $g = getenv($name);
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? ($g !== false ? $g : null) ?? static::getDefault()[$name] ?? null;
        $result = $value !== null ? (string)$value : (string)$default;

        static::$cache[static::envKey()][$name] = $result;
        return $result;
    }

    public static function ini(string $name, $default = ''): string {
        if (array_key_exists($name, static::$cache[static::iniKey()] ?? []))
            return static::$cache[static::iniKey()][$name];

        $value = ini_get($name);
        $result = $value !== false ? $value : (string)$default;

        static::$cache[static::iniKey()][$name] = $result;
        return $result;
    }

    public static function config(string $key = '', $default = null) {
        if ($key === '')
            return static::$cache[static::configKey()] ?? [];

        $cacheKey = static::configKey() . '.' . $key;

        $cached = Main::dotGet(static::$cache, $cacheKey, static::sentinel());

        if ($cached !== static::sentinel()) return $cached;

        if (static::$configPath === '') return $default;

        if (is_dir(static::$configPath)) {
            $fileKey = explode('.', $key)[0];
            if (!array_key_exists($fileKey, static::$cache[static::configKey()] ?? [])) {
                $base = rtrim(static::$configPath, '/\\') . DIRECTORY_SEPARATOR . $fileKey;
                foreach ([$base . '.php', $base . '.json', $base] as $path) {
                    if (file_exists($path) && is_file($path)) {
                        static::$cache[static::configKey()][$fileKey] = static::parseFile($path);
                        break;
                    }
                }
            }
        } elseif (is_file(static::$configPath) && !array_key_exists(static::fileKey(), static::$cache[static::configKey()] ?? [])) {
            $data = static::parseFile(static::$configPath);
            static::$cache[static::configKey()] = array_merge(
                static::$cache[static::configKey()] ?? [],
                $data,
                [static::fileKey() => true]
            );
        }

        $cached = Main::dotGet(static::$cache, $cacheKey, static::sentinel());
        return ($cached !== static::sentinel()) ? $cached : $default;
    }

    public static function setConfig(string $key, $value): void {
        Main::dotSet(static::$cache, static::configKey() . '.' . $key, $value);
    }

    public static function getImmutableConfig(string $key, string $subKey = '') {
        if ($subKey === '')
            return static::$cache[$key] ?? [];

        $value = Main::dotGet(static::$cache, $key . '.' . $subKey, static::sentinel());
        return $value === static::sentinel() ? null : $value;
    }

    public static function setImmutableConfig(string $key, string $subKey, $value): void {
        Main::dotSet(static::$cache, $key . '.' . $subKey, $value);
    }

    public static function fillConfig(string $key, $value): void {
        if (is_array($value)) {
            foreach (Main::dotFlatten($value, $key) as $path => $v) {
                $cacheKey = static::configKey() . '.' . $path;
                if (Main::dotGet(static::$cache, $cacheKey, static::sentinel()) === static::sentinel())
                    Main::dotSet(static::$cache, $cacheKey, $v);
            }
        } else {
            $cacheKey = static::configKey() . '.' . $key;
            if (Main::dotGet(static::$cache, $cacheKey, static::sentinel()) === static::sentinel())
                Main::dotSet(static::$cache, $cacheKey, $value);
        }
    }

    public static function fillImmutableConfig(string $key, string $subKey, $value): void {
        if (is_array($value)) {
            foreach (Main::dotFlatten($value, $subKey) as $path => $v) {
                $cacheKey = $key . '.' . $path;
                if (Main::dotGet(static::$cache, $cacheKey, static::sentinel()) === static::sentinel())
                    Main::dotSet(static::$cache, $cacheKey, $v);
            }
        } else {
            $cacheKey = $subKey !== '' ? $key . '.' . $subKey : $key;
            if (Main::dotGet(static::$cache, $cacheKey, static::sentinel()) === static::sentinel())
                Main::dotSet(static::$cache, $cacheKey, $value);
        }
    }

    private static function getDefault(): array {
        static $values = null;

        if ($values === null) {
            $values = [
                'COMPOSER_ROOT' => (function() {
                    $dir = getcwd() ?: __DIR__;
                    while (true) {
                        if (file_exists($dir . DIRECTORY_SEPARATOR . 'composer.json')) return $dir;
                        $parent = dirname($dir);
                        if ($parent === $dir) return '';
                        $dir = $parent;
                    }
                })()
            ];
        }

        return $values;
    }

    private static function sentinel(): object {
        static $s = null;
        if ($s === null) $s = new \stdClass();
        return $s;
    }

    private static function envKey(): string {
        static $k = null;
        if ($k === null) $k = "\0" . bin2hex(random_bytes(6));
        return $k;
    }

    private static function configKey(): string {
        static $k = null;
        if ($k === null) $k = "\0" . bin2hex(random_bytes(6));
        return $k;
    }

    private static function iniKey(): string {
        static $k = null;
        if ($k === null) $k = "\0" . bin2hex(random_bytes(6));
        return $k;
    }

    private static function fileKey(): string {
        static $k = null;
        if ($k === null) $k = "\0" . bin2hex(random_bytes(6));
        return $k;
    }

    private static function parseFile(string $path): array {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === 'php') {
            $result = require $path;
            return is_array($result) ? $result : [];
        }

        $content = file_get_contents($path);

        if ($ext === 'json') {
            $result = json_decode($content, true);
            return is_array($result) ? $result : [];
        }

        $result = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $content)) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $k = trim(substr($line, 0, $pos));
            $v = trim(substr($line, $pos + 1));
            if (strlen($v) >= 2 && (($v[0] === '"' && $v[-1] === '"') || ($v[0] === "'" && $v[-1] === "'")))
                $v = substr($v, 1, -1);
            if ($k !== '') $result[$k] = $v;
        }
        return $result;
    }
}
