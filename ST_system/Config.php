<?php

namespace ST_system;

final class Config {

    private static array $cache = [];
    private static string $configPath = '';

    public static function init(array $params = []): void {
        static $inited = false;

        if ($inited)
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
            \Dotenv\Dotenv::createImmutable($dotenvDir)->safeLoad();

        static::$configPath = $configPath;
        $inited = true;
    }

    public static function reload(): void {
        static::$cache[static::envKey()]    = [];
        static::$cache[static::configKey()] = [];
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

    public static function config(string $key = '', $default = null) {
        if ($key === '')
            return static::$cache[static::configKey()] ?? [];

        $cacheKey = static::configKey() . '.' . $key;

        $cached = static::getFromCache($cacheKey);

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

        $cached = static::getFromCache($cacheKey);
        return ($cached !== static::sentinel()) ? $cached : $default;
    }

    public static function setConfig(string $key, $value): void {
        static::writeTo(static::configKey() . '.' . $key, $value);
    }

    public static function getImmutableConfig(string $key, string $subKey = '') {
        if ($subKey === '')
            return static::$cache[$key] ?? [];

        return static::getFromCache($key . '.' . $subKey);
    }

    public static function setImmutableConfig(string $key, string $subKey, $value): void {
        static::writeTo($key . '.' . $subKey, $value);
    }

    private static function writeTo(string $key, $value): void {
        $segments = explode('.', $key);
        $current = &static::$cache;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $current[$segment] = $value;
            } else {
                if (!isset($current[$segment]) || !is_array($current[$segment]))
                    $current[$segment] = [];
                $current = &$current[$segment];
            }
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

    private static function fileKey(): string {
        static $k = null;
        if ($k === null) $k = "\0" . bin2hex(random_bytes(6));
        return $k;
    }

    private static function getFromCache(string $key) {
        $segments = explode('.', $key);
        $current = static::$cache;

        foreach ($segments as $segment) {
            if (!is_array($current))
                return static::sentinel();

            if (array_key_exists($segment, $current)) {
                $current = $current[$segment];
                continue;
            }

            if (ctype_digit((string)$segment) && array_key_exists((int)$segment, $current)) {
                $current = $current[(int)$segment];
                continue;
            }

            return static::sentinel();
        }

        return $current;
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
