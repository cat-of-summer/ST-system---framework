<?php

namespace ST_system;

use ST_system\Main;

/**
 * Конфигурация приложения: окружение, ini и файлы конфигурации.
 *
 * Статические методы — глобальный конфиг процесса (`init()` один раз). Экземпляр
 * `new Config($dir)` — тот же каталог файлов как обычный объект: их в процессе может быть сколько
 * угодно, каждый перечитывается сам (`changed()`, `refresh()`).
 *
 * Раскладка каталога одна для обоих: верхний ключ `X` — каталог `X/` (файл `Y.php` или `Y.json` —
 * подключ `Y`, вложенный каталог — следующий уровень), поверх него глубоко сливается файл `X.php`
 * или `X.json`. Словари сливаются по ключам; список, скаляр и замыкание из файла заменяют значение.
 */
final class Config {

    private static array $cache = [];
    private static string $configPath = '';

    private string $dir = '';
    private bool $strict = true;
    /** @var array<string, mixed>|null */
    private ?array $data = null;
    private ?string $print = null;
    /** @var array<string, int> файл → mtime при последнем чтении */
    private array $seen = [];

    /**
     * Каталог конфигурации как объект.
     *
     * @param bool $strict файл, вернувший не массив, и битый JSON — `\UnexpectedValueException`;
     *        без strict — пустой массив, как у глобального конфига
     */
    public function __construct(string $dir, bool $strict = true) {
        $this->dir = rtrim($dir, '/\\');
        $this->strict = $strict;
    }

    public function dir(): string {
        return $this->dir;
    }

    /**
     * Значение по dot-пути; `''` — вся конфигурация.
     *
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key = '', $default = null) {
        $data = $this->all();

        return $key === '' ? $data : Main::dotGet($data, $key, $default);
    }

    /** Есть ли верхний ключ: файл `X.php`, `X.json` или каталог `X/`. */
    public function has(string $name): bool {
        return array_key_exists($name, $this->all());
    }

    /** @return array<string, mixed> все верхние ключи; читается один раз до refresh() */
    public function all(): array {
        if ($this->data !== null)
            return $this->data;

        $data = [];
        foreach ($this->names() as $name)
            $data[$name] = $this->load($name);

        $this->print ??= $this->fingerprint();

        return $this->data = $data;
    }

    /**
     * Один верхний ключ с диска, без кеша: каталог `X/`, поверх — `X.php` или `X.json`.
     *
     * @return array<mixed>
     */
    public function load(string $name): array {
        $base = $this->dir . DIRECTORY_SEPARATOR . str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $name);
        $value = is_dir($base) ? $this->loadDir($base) : [];
        foreach ([$base . '.php', $base . '.json'] as $file) {
            if (is_file($file))
                return static::merge($value, $this->parse($file));
        }

        return $value;
    }

    /** @return list<string> файлы конфигурации каталога в порядке обхода */
    public function files(): array {
        if (!is_dir($this->dir))
            return [];

        $files = [];
        $walk = static function (string $dir) use (&$walk, &$files): void {
            foreach (static::entries($dir) as $entry) {
                $path = $dir . DIRECTORY_SEPARATOR . $entry;
                if (is_dir($path))
                    $walk($path);
                elseif (static::isConfigFile($entry))
                    $files[] = $path;
            }
        };
        $walk($this->dir);

        return $files;
    }

    /** Отпечаток состава файлов, их размеров и времени изменения. */
    public function fingerprint(): string {
        $parts = [];
        foreach ($this->files() as $file) {
            clearstatcache(true, $file);
            $parts[] = substr($file, strlen($this->dir)) . ':' . (@filemtime($file) ?: 0) . ':' . (@filesize($file) ?: 0);
        }

        return sha1(implode("\n", $parts));
    }

    /** SHA-256 содержимого всех файлов: одинаков у процессов, читающих одни и те же байты. */
    public function contentHash(): string {
        $ctx = hash_init('sha256');
        foreach ($this->files() as $file) {
            hash_update($ctx, substr($file, strlen($this->dir)) . "\0" . (string) @file_get_contents($file) . "\0");
        }

        return hash_final($ctx);
    }

    /** Изменился ли каталог с последнего чтения. */
    public function changed(): bool {
        return $this->print === null || $this->print !== $this->fingerprint();
    }

    /**
     * Сбросить прочитанное: следующее обращение читает файлы заново. Изменённые с прошлого чтения
     * PHP-файлы вычищаются из opcache — иначе при `opcache.revalidate_freq` процесс ещё секунды
     * получал бы старый массив.
     */
    public function refresh(): void {
        if (function_exists('opcache_invalidate')) {
            foreach ($this->seen as $file => $mtime) {
                clearstatcache(true, $file);
                if (!is_file($file) || (@filemtime($file) ?: 0) !== $mtime)
                    @opcache_invalidate($file, true);
            }
        }
        $this->data = null;
        $this->print = null;
        $this->seen = [];
    }

    /**
     * Глубокое слияние: словари сливаются по ключам, всё остальное из `$over` заменяет значение.
     *
     * @param array<mixed> $base
     * @param array<mixed> $over
     * @return array<mixed>
     */
    public static function merge(array $base, array $over): array {
        if ($over !== [] && static::isList($over))
            return $over;

        foreach ($over as $key => $value) {
            if (is_array($value) && is_array($base[$key] ?? null) && !static::isList($value) && !static::isList($base[$key]))
                $base[$key] = static::merge($base[$key], $value);
            else
                $base[$key] = $value;
        }

        return $base;
    }

    /** @return list<string> верхние ключи: имена файлов и каталогов без расширения */
    private function names(): array {
        if (!is_dir($this->dir))
            return [];

        $names = [];
        foreach (static::entries($this->dir) as $entry) {
            if (is_dir($this->dir . DIRECTORY_SEPARATOR . $entry))
                $names[$entry] = true;
            elseif (static::isConfigFile($entry))
                $names[pathinfo($entry, PATHINFO_FILENAME)] = true;
        }
        $names = array_map('strval', array_keys($names));
        sort($names);

        return $names;
    }

    /** @return array<string, mixed> */
    private function loadDir(string $dir): array {
        $value = [];
        $subdirs = [];
        foreach (static::entries($dir) as $entry) {
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path))
                $subdirs[$entry] = $path;
            elseif (static::isConfigFile($entry))
                $value[pathinfo($entry, PATHINFO_FILENAME)] = $this->parse($path);
        }
        foreach ($subdirs as $name => $path)
            $value[$name] = static::merge($this->loadDir($path), is_array($value[$name] ?? null) ? $value[$name] : []);

        return $value;
    }

    /** @return array<mixed> */
    private function parse(string $path): array {
        $this->seen[$path] = @filemtime($path) ?: 0;
        $php = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php';
        $result = $php
            ? (static function (string $__file) { return require $__file; })($path)
            : json_decode((string) file_get_contents($path), true);

        if (is_array($result))
            return $result;
        if ($this->strict)
            throw new \UnexpectedValueException($php ? "$path: файл конфигурации должен вернуть массив" : "$path: невалидный JSON");

        return [];
    }

    /** @return list<string> имена в каталоге по порядку, без `.`, `..` и скрытых */
    private static function entries(string $dir): array {
        $entries = array_values(array_filter(scandir($dir) ?: [], static function (string $entry): bool {
            return $entry !== '' && $entry[0] !== '.';
        }));
        sort($entries);

        return $entries;
    }

    private static function isConfigFile(string $name): bool {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return $ext === 'php' || $ext === 'json';
    }

    /** @param array<mixed> $value */
    private static function isList(array $value): bool {
        $i = 0;
        foreach ($value as $key => $_) {
            if ($key !== $i++)
                return false;
        }

        return true;
    }

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
            static::loadEnv($dotenvDir);

        static::$configPath = $configPath;
        $initialized = true;
    }

    private static function loadEnv(string $dir): void {
        $path = rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($path) || !is_readable($path))
            return;

        $content = file_get_contents($path);
        if ($content === false)
            return;

        if (strncmp($content, "\xEF\xBB\xBF", 3) === 0)
            $content = substr($content, 3);
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $lines = explode("\n", $content);
        $count = count($lines);
        $vars  = [];

        for ($i = 0; $i < $count; $i++) {
            $trimmed = ltrim($lines[$i]);

            if ($trimmed === '' || $trimmed[0] === '#')
                continue;

            if (strncmp($trimmed, 'export', 6) === 0 && isset($trimmed[6]) && ($trimmed[6] === ' ' || $trimmed[6] === "\t"))
                $trimmed = ltrim(substr($trimmed, 7));

            $eq = strpos($trimmed, '=');
            if ($eq === false)
                continue;

            $name = rtrim(substr($trimmed, 0, $eq));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $name))
                continue;

            $rest = ltrim(substr($trimmed, $eq + 1), " \t");

            if ($rest !== '' && ($rest[0] === '"' || $rest[0] === "'")) {
                $quote  = $rest[0];
                $buf    = substr($rest, 1);
                $value  = '';
                $closed = false;

                while (true) {
                    $len = strlen($buf);
                    $j   = 0;
                    while ($j < $len) {
                        $ch = $buf[$j];
                        if ($quote === '"' && $ch === '\\' && $j + 1 < $len) {
                            $value .= $ch . $buf[$j + 1];
                            $j += 2;
                            continue;
                        }
                        if ($ch === $quote) {
                            $closed = true;
                            break;
                        }
                        $value .= $ch;
                        $j++;
                    }
                    if ($closed)
                        break;
                    $i++;
                    if ($i >= $count)
                        break;
                    $value .= "\n";
                    $buf = $lines[$i];
                }

                if ($quote === '"') {
                    $value = static::interpolateEnv($value, $vars);
                    $value = static::unescapeEnv($value);
                }
            } else {
                $value = $rest;
                $vlen  = strlen($value);
                for ($k = 0; $k < $vlen; $k++) {
                    if ($value[$k] === '#' && ($k === 0 || $value[$k - 1] === ' ' || $value[$k - 1] === "\t")) {
                        $value = substr($value, 0, $k);
                        break;
                    }
                }
                $value = static::interpolateEnv(trim($value), $vars);
            }

            $vars[$name] = $value;
        }

        foreach ($vars as $name => $value) {
            if (array_key_exists($name, $_ENV) || array_key_exists($name, $_SERVER) || getenv($name) !== false)
                continue;
            $_ENV[$name]    = $value;
            $_SERVER[$name] = $value;
            if (function_exists('putenv'))
                @putenv($name . '=' . $value);
        }
    }

    private static function unescapeEnv(string $value): string {
        if (strpos($value, '\\') === false)
            return $value;

        $result = '';
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $ch = $value[$i];
            if ($ch === '\\' && $i + 1 < $len) {
                $next = $value[$i + 1];
                switch ($next) {
                    case 'n':  $result .= "\n";   break;
                    case 'r':  $result .= "\r";   break;
                    case 't':  $result .= "\t";   break;
                    case 'f':  $result .= "\f";   break;
                    case 'v':  $result .= "\v";   break;
                    case '"':  $result .= '"';    break;
                    case '\\': $result .= '\\';   break;
                    case '$':  $result .= '$';    break;
                    default:   $result .= '\\' . $next; break;
                }
                $i++;
                continue;
            }
            $result .= $ch;
        }
        return $result;
    }

    private static function interpolateEnv(string $value, array $vars): string {
        if (strpos($value, '$') === false)
            return $value;

        return preg_replace_callback(
            '/(?<!\\\\)\$(?:\{([A-Za-z_][A-Za-z0-9_.]*)\}|([A-Za-z_][A-Za-z0-9_.]*))/',
            static function ($m) use ($vars) {
                $name = (isset($m[1]) && $m[1] !== '') ? $m[1] : ($m[2] ?? '');
                if ($name === '')
                    return $m[0];
                if (array_key_exists($name, $vars))
                    return $vars[$name];
                $g = getenv($name);
                if ($g !== false)
                    return $g;
                if (array_key_exists($name, $_ENV))
                    return (string)$_ENV[$name];
                return array_key_exists($name, $_SERVER) ? (string)$_SERVER[$name] : '';
            },
            $value
        );
    }

    public static function reload(): void {
        static::$cache[static::configKey()] = [];
        static::$cache[static::iniKey()]    = [];
    }

    public static function env(string $name, $default = ''): string {
        $g = getenv($name);
        $value = ($g !== false ? $g : null) ?? $_ENV[$name] ?? $_SERVER[$name] ?? static::getDefault()[$name] ?? null;

        return $value !== null ? (string)$value : (string)$default;
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
                // Ключ с неймспейсом (`ST_system\View`) — вложенные папки на любой ОС.
                $base = rtrim(static::$configPath, '/\\') . DIRECTORY_SEPARATOR
                      . str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $fileKey);
                // Каталог `X/` и файл `X.php` поверх него — та же раскладка, что у экземпляра
                if (is_dir($base) || is_file($base . '.php') || is_file($base . '.json'))
                    static::$cache[static::configKey()][$fileKey] = (new self(static::$configPath, false))->load($fileKey);
                elseif (is_file($base))
                    static::$cache[static::configKey()][$fileKey] = static::parseFile($base);
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

        if ($values !== null) return $values;

        $norm = static function ($path): string {
            if (!is_string($path) || $path === '') return '';

            $real = realpath($path);

            return $real === false ? '' : rtrim(str_replace('\\', '/', $real), '/');
        };

        $vendorDir = (static function () use ($norm): string {
            if (class_exists(\Composer\Autoload\ClassLoader::class, false)) {
                try {
                    $file = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();
                } catch (\ReflectionException $e) {
                    $file = false;
                }

                if ($file !== false) return $norm(dirname($file, 2));
            }

            if (isset($GLOBALS['_composer_autoload_path']))
                return $norm(dirname((string) $GLOBALS['_composer_autoload_path']));

            $self = str_replace('\\', '/', __DIR__);
            $pos  = strrpos($self, '/vendor/');

            return $pos !== false ? $norm(substr($self, 0, $pos) . '/vendor') : '';
        })();

        $isRoot = static function (string $dir) use ($vendorDir): bool {
            if ($dir === '' || !is_file($dir . '/composer.json')) return false;

            return $vendorDir === '' || strncmp($dir . '/', $vendorDir . '/', strlen($vendorDir) + 1) !== 0;
        };

        $climb = static function ($start) use ($norm, $isRoot): string {
            $dir = $norm($start);

            if ($dir !== '' && !is_dir($dir)) $dir = dirname($dir);

            while ($dir !== '') {
                if ($isRoot($dir)) return $dir;

                $parent = dirname($dir);

                if ($parent === $dir) return '';

                $dir = $parent;
            }

            return '';
        };

        $composerRoot = (static function () use ($norm, $isRoot, $climb, $vendorDir): string {
            if (class_exists(\Composer\InstalledVersions::class)) {
                try {
                    $path = \Composer\InstalledVersions::getRootPackage()['install_path'] ?? '';
                } catch (\Throwable $e) {
                    $path = '';
                }

                $root = $norm($path);

                if ($root !== '' && is_file($root . '/composer.json')) return $root;
            }

            if (isset($GLOBALS['_composer_autoload_path'])) {
                $root = $norm(dirname((string) $GLOBALS['_composer_autoload_path'], 2));

                if ($isRoot($root)) return $root;
            }

            if ($vendorDir !== '') {
                $root = $norm(dirname($vendorDir));

                if ($isRoot($root)) return $root;
            }

            $manifest = getenv('COMPOSER');

            if (is_string($manifest) && $manifest !== '' && is_file($manifest)) {
                $root = $norm(dirname($manifest));

                if ($root !== '') return $root;
            }

            foreach ([$_SERVER['SCRIPT_FILENAME'] ?? null, $_SERVER['argv'][0] ?? null, $_SERVER['DOCUMENT_ROOT'] ?? null] as $entry) {
                $root = $climb($entry);

                if ($root !== '') return $root;
            }

            $root = $climb(__DIR__);

            if ($root !== '') return $root;

            return $climb(getcwd());
        })();

        $values = [
            'COMPOSER_ROOT' => $composerRoot
        ];

        return $values;
    }

    private static function sentinel(): object {
        static $s = null;
        if ($s === null) $s = new \stdClass();
        return $s;
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
