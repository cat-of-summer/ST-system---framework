<?php

namespace ST_system;

use ST_system\Traits\HasConfig;
use ST_system\Cache\CacheManager;

final class Lang {

    use HasConfig {
        setConfig as private traitSetConfig;
    }

    protected static function getDefaultConfig(): array {
        return [
            'source'     => ['~' => '~'],
            'locale'     => 'ru',
            'fallback'   => 'en',
            'extensions' => ['php', 'json'],
            'dir_name'   => '',
            'cache' => [
                'use'     => true,
                'driver'  => 'filesystem',
                'dir'     => Main::glue([CacheManager::config('default.dir'), Main::basename(static::class)], '/'),
                'check'   => 'mtime',
                'ttl'     => 60,
                'exclude' => [],
            ],
            'plural' => [],
        ];
    }

    private const RESERVED = [
        'get', 'set', 'has', 'all', 'choice', 'config', 'setConfig', 'applyConfig',
        'setLocale', 'getLocale', 'setFallback', 'getFallback', 'purge', 'getUsedFiles', 'sources',
    ];

    private const ABSENT = "\0__lang_absent__";

    private static ?string $locale    = null;
    private static ?string $fallback  = null;
    private static ?array  $sources   = null;
    private static ?array  $excludes  = null;
    private static array   $maps      = [];
    private static array   $fileData  = [];
    private static array   $runtime   = [];
    private static array   $usedFiles = [];

    public static function setConfig(array $config = []): void {
        self::$sources  = null;
        self::$excludes = null;
        self::$maps     = [];
        self::$fileData = [];

        self::traitSetConfig($config);
    }

    public static function setLocale(string $locale): void {
        self::$locale = $locale;
    }

    public static function getLocale(): string {
        return self::$locale ?? self::resolveLocaleValue(static::config('locale'));
    }

    public static function setFallback(string $locale): void {
        self::$fallback = $locale;
    }

    public static function getFallback(): string {
        return self::$fallback ?? self::resolveLocaleValue(static::config('fallback'));
    }

    private static function resolveLocaleValue($value): string {
        if ($value instanceof \Closure || (is_callable($value) && !is_string($value)))
            $value = $value();

        return (string)$value;
    }

    public static function __callStatic(string $name, array $args) {
        if (in_array($name, self::RESERVED, true) || !isset(self::sources()[$name]))
            throw new \BadMethodCallException("Method ".__CLASS__."::{$name}() not found");

        $key = (string)array_shift($args);

        return self::get($name.':'.$key, ...$args);
    }

    public static function get(string $key, array $params = [], $default = null, ?string $locale = null) {
        $locale = $locale ?? self::getLocale();
        $value  = self::find($key, $locale);

        if ($value === self::ABSENT && ($fb = self::getFallback()) !== '' && $fb !== $locale)
            $value = self::find($key, $fb);

        if ($value === self::ABSENT)
            $value = $default ?? $key;

        return is_string($value) && $params ? strtr($value, self::placeholders($params)) : $value;
    }

    public static function choice(string $key, $number, array $params = [], $default = null, ?string $locale = null): string {
        $locale = $locale ?? self::getLocale();
        $value  = self::find($key, $locale);
        $used   = $locale;

        if ($value === self::ABSENT && ($fb = self::getFallback()) !== '' && $fb !== $locale) {
            $value = self::find($key, $fb);
            $used  = $fb;
        }

        if ($value === self::ABSENT)
            $value = $default ?? $key;

        $forms = is_array($value)
            ? array_values($value)
            : array_map('trim', explode('|', (string)$value));

        $index = min(self::pluralIndex((int)$number, $used), count($forms) - 1);

        $params += ['count' => $number];

        return strtr((string)$forms[$index], self::placeholders($params));
    }

    public static function has(string $key, ?string $locale = null): bool {
        $locale = $locale ?? self::getLocale();

        if (self::find($key, $locale) !== self::ABSENT) return true;

        $fb = self::getFallback();
        return $fb !== '' && $fb !== $locale && self::find($key, $fb) !== self::ABSENT;
    }

    public static function set($key, $value = null, ?string $locale = null): void {
        $locale = $locale ?? self::getLocale();
        $flat   = is_array($key) ? Main::dotFlatten($key) : [(string)$key => $value];

        foreach ($flat as $k => $v)
            self::$runtime[$locale][$k] = $v;
    }

    public static function all(string $prefix = '', ?string $locale = null): array {
        $locale = $locale ?? self::getLocale();
        [$sourceName, $path] = self::splitKey($prefix);

        $locales = [];
        if (($fb = self::getFallback()) !== '' && $fb !== $locale) $locales[] = $fb;
        $locales[] = $locale;

        $result = [];
        foreach ($locales as $loc) {
            foreach (self::fullMap($sourceName, $loc) as $k => $v)
                self::collectInto($result, $k, $path, $v);

            foreach (self::$runtime[$loc] ?? [] as $k => $v) {
                if ($prefix === '' && strpos($k, ':') !== false) continue;
                self::collectInto($result, $k, $prefix, $v);
            }
        }

        return $result;
    }

    private static function collectInto(array &$result, string $key, string $prefix, $value): void {
        if ($prefix === '') {
            Main::dotSet($result, $key, $value);
            return;
        }

        if ($key === $prefix) {
            if (is_array($value)) $result = array_replace($result, $value);
            return;
        }

        if (strncmp($key, $prefix.'.', strlen($prefix) + 1) === 0)
            Main::dotSet($result, substr($key, strlen($prefix) + 1), $value);
    }

    public static function purge(): void {
        CacheManager::make('', [
            'driver' => static::config('cache.driver'),
            'dir'    => static::config('cache.dir'),
        ])->purgeBase();

        self::$maps     = [];
        self::$fileData = [];
    }

    public static function getUsedFiles(): array {
        return array_keys(self::$usedFiles);
    }

    private static function find(string $key, string $locale) {
        if (isset(self::$runtime[$locale]) && array_key_exists($key, self::$runtime[$locale]))
            return self::$runtime[$locale][$key];

        [$sourceName, $path] = self::splitKey($key);

        $sources = self::sources();
        if (!isset($sources[$sourceName]))
            throw new \RuntimeException(__CLASS__.": источник '{$sourceName}' не найден");

        if ($path === '') return self::ABSENT;

        $source   = $sources[$sourceName];
        $segments = explode('.', $path);

        if (!static::config('cache.use') || $source['excluded'])
            return self::resolveCold($source, $segments, $locale, false);

        if (self::$excludes['prefixes'] || self::$excludes['regexes']) {
            $value = self::resolveCold($source, $segments, $locale, true);
            if ($value !== self::ABSENT) return $value;
        }

        return self::mapLookup(self::compiledMap($sourceName, $locale), $path);
    }

    private static function splitKey(string $key): array {
        $p = strpos($key, ':');
        return $p === false ? ['~', $key] : [substr($key, 0, $p), substr($key, $p + 1)];
    }

    private static function resolveCold(array $source, array $segments, string $locale, bool $onlyExcluded) {
        $n      = count($segments);
        $suffix = $source['dir_name'] !== '' ? '/'.$source['dir_name'] : '';

        $dirs = [$source['dir']];
        for ($i = 1; $i < $n; $i++)
            $dirs[$i] = $dirs[$i - 1].'/'.$segments[$i - 1];

        if ($onlyExcluded) {
            $max = $n - 1;
        } else {
            $max = 0;
            for ($i = 1; $i < $n; $i++) {
                if (!is_dir($dirs[$i])) break;
                $max = $i;
            }
        }

        for ($i = $max; $i >= 0; $i--) {
            $dir = $dirs[$i].$suffix;

            foreach ($source['ext'] as $ext) {
                $file = $dir.'/'.$locale.'.'.$ext;

                if ($onlyExcluded && !self::isExcluded($dir) && !self::isExcluded($file)) continue;
                if (!is_file($file)) continue;

                $data = self::parseFile($file);
                if ($data === null) continue;

                $value = Main::dotGet($data, implode('.', array_slice($segments, $i)), self::ABSENT);
                if ($value !== self::ABSENT) {
                    self::$usedFiles[$file] = true;
                    return $value;
                }
            }
        }

        return self::ABSENT;
    }

    private static function mapLookup(array $map, string $path) {
        if (array_key_exists($path, $map)) return $map[$path];

        $prefix = $path;
        while (($p = strrpos($prefix, '.')) !== false) {
            $prefix = substr($prefix, 0, $p);
            if (array_key_exists($prefix, $map)) {
                return is_array($map[$prefix])
                    ? Main::dotGet($map[$prefix], substr($path, strlen($prefix) + 1), self::ABSENT)
                    : self::ABSENT;
            }
        }

        $sub = [];
        $pfx = $path.'.';
        $len = strlen($pfx);
        foreach ($map as $k => $v)
            if (strncmp($k, $pfx, $len) === 0)
                Main::dotSet($sub, substr($k, $len), $v);

        return $sub ?: self::ABSENT;
    }

    private static function fullMap(string $sourceName, string $locale): array {
        $sources = self::sources();
        if (!isset($sources[$sourceName]))
            throw new \RuntimeException(__CLASS__.": источник '{$sourceName}' не найден");

        if (static::config('cache.use') && !$sources[$sourceName]['excluded'])
            return self::compiledMap($sourceName, $locale);

        $memoKey = $sourceName."\0".$locale;
        if (isset(self::$maps[$memoKey])) return self::$maps[$memoKey];

        [$map, , $files] = self::buildMap($sources[$sourceName], $locale);
        foreach ($files as $f) self::$usedFiles[$f] = true;

        return self::$maps[$memoKey] = $map;
    }

    private static function compiledMap(string $sourceName, string $locale): array {
        $memoKey = $sourceName."\0".$locale;
        if (isset(self::$maps[$memoKey])) return self::$maps[$memoKey];

        $cache = CacheManager::make([__CLASS__, $sourceName, $locale, self::sources()[$sourceName], self::$excludes], [
            'driver' => static::config('cache.driver'),
            'dir'    => static::config('cache.dir'),
            'ttl'    => -1,
        ]);

        $meta  = $cache->getMeta();
        $check = static::config('cache.check');
        $fresh = false;

        if (is_array($meta['deps'] ?? null) && isset($meta['stamp'])) {
            if ($check === 'never') {
                $fresh = true;
            } elseif ($check === 'ttl' && (time() - (int)($meta['checked'] ?? 0)) < (int)static::config('cache.ttl')) {
                $fresh = true;
            } else {
                $fresh = $meta['stamp'] === self::depStamp($meta['deps']);
                if ($fresh && $check === 'ttl')
                    $cache->setMeta(['checked' => time()], -1);
            }
        }

        if ($fresh) {
            $map = $cache->get();
            if (is_array($map)) {
                foreach ((array)($meta['files'] ?? []) as $f) self::$usedFiles[$f] = true;
                return self::$maps[$memoKey] = $map;
            }
        }

        [$map, $deps, $files] = self::buildMap(self::sources()[$sourceName], $locale);

        $cache->set($map, -1, '', [
            'stamp'   => self::depStamp($deps),
            'deps'    => $deps,
            'files'   => $files,
            'checked' => time(),
        ]);

        foreach ($files as $f) self::$usedFiles[$f] = true;

        return self::$maps[$memoKey] = $map;
    }

    private static function buildMap(array $source, string $locale): array {
        $dirName = $source['dir_name'];

        $names = [];
        foreach ($source['ext'] as $priority => $ext)
            $names[$locale.'.'.$ext] = $priority;

        $dirs  = [];
        $found = [];
        $queue = [[$source['dir'], '', 0, $dirName === '']];

        while ($queue) {
            [$dir, $prefix, $depth, $isLang] = array_shift($queue);
            $dirs[] = $dir;

            foreach ((@scandir($dir) ?: []) as $item) {
                if ($item[0] === '.') continue;
                $path = $dir.'/'.$item;

                if (is_dir($path)) {
                    if (self::isExcluded($path)) continue;

                    if ($dirName !== '' && $item === $dirName)
                        $queue[] = [$path, $prefix, $depth, true];
                    elseif ($dirName === '' || !$isLang)
                        $queue[] = [$path, $prefix === '' ? $item : $prefix.'.'.$item, $depth + 1, $dirName === ''];
                } elseif ($isLang && isset($names[$item]) && !self::isExcluded($path)) {
                    $found[] = [$depth, $names[$item], $path, $prefix];
                }
            }
        }

        usort($found, fn($a, $b) => $a[0] <=> $b[0] ?: $b[1] <=> $a[1]);

        $map = [];
        foreach ($found as [, , $file, $prefix]) {
            $data = self::parseFile($file);
            if ($data === null) continue;

            foreach (Main::dotFlatten($data, $prefix) as $k => $v)
                $map[$k] = $v;
        }

        $files = array_column($found, 2);

        return [$map, array_merge($dirs, $files), $files];
    }

    private static function depStamp(array $paths): string {
        clearstatcache();

        $stamps = [];
        foreach ($paths as $p)
            $stamps[$p] = file_exists($p) ? (int)filemtime($p) : 0;

        return Main::hash($stamps);
    }

    private static function parseFile(string $file): ?array {
        if (array_key_exists($file, self::$fileData)) return self::$fileData[$file];

        $ext = strtolower(substr($file, strrpos($file, '.') + 1));

        $data = $ext === 'php'
            ? (static function () use ($file) { return require $file; })()
            : json_decode((string)file_get_contents($file), true);

        return self::$fileData[$file] = is_array($data) ? $data : null;
    }

    private static function sources(): array {
        if (self::$sources !== null) return self::$sources;

        self::buildExcludes();

        $source = static::config('source');
        $source = is_array($source) ? $source : ['~' => (string)$source];

        if (!isset($source['~']))
            $source = ['~' => '~'] + $source;

        $map = [];
        foreach ($source as $name => $spec) {
            $name = (string)$name;
            if ($name === '' || in_array($name, self::RESERVED, true))
                throw new \LogicException(__CLASS__.": источник '{$name}' конфликтует с зарезервированным методом");

            if (is_array($spec)) {
                $dir      = (string)($spec['source'] ?? '');
                $override = $spec;
                unset($override['source']);
            } else {
                $dir      = (string)$spec;
                $override = [];
            }

            if ($dir === '') continue;

            $abs = Main::preparePath($dir);

            $map[$name] = [
                'dir'      => $abs,
                'ext'      => self::extList($override['extensions'] ?? null),
                'dir_name' => trim((string)($override['dir_name'] ?? static::config('dir_name')), '/'),
                'excluded' => isset(self::$excludes['names'][$name]) || self::isExcluded($abs),
            ];
        }

        return self::$sources = $map;
    }

    private static function extList($ext): array {
        $list = [];
        foreach ((array)($ext ?? static::config('extensions')) as $e) {
            $e = strtolower(ltrim((string)$e, '.'));
            if ($e !== '' && !in_array($e, $list, true)) $list[] = $e;
        }
        return $list ?: ['php', 'json'];
    }

    private static function buildExcludes(): void {
        if (self::$excludes !== null) return;

        $raw = static::config('cache.exclude');
        $raw = is_array($raw) ? $raw : (($raw === '' || $raw === null) ? [] : [$raw]);

        $names = $prefixes = $regexes = [];
        foreach ($raw as $e) {
            $e = (string)$e;
            if ($e === '') continue;

            if (strncmp($e, 'regex:', 6) === 0) {
                $pattern = substr($e, 6);
                if (@preg_match($pattern, '') === false)
                    throw new \InvalidArgumentException(__CLASS__.": некорректное регулярное выражение в cache.exclude: '{$pattern}'");
                $regexes[] = $pattern;
            } elseif (strpbrk($e, '/~\\') !== false) {
                $prefixes[] = Main::preparePath($e);
            } else {
                $names[$e] = true;
            }
        }

        foreach ([static::config('cache.dir'), CacheManager::config('default.dir')] as $sys) {
            $sys = (string)$sys;
            if ($sys !== '') $prefixes[] = Main::preparePath($sys);
        }

        self::$excludes = ['names' => $names, 'prefixes' => $prefixes, 'regexes' => $regexes];
    }

    private static function isExcluded(string $absPath): bool {
        if (self::$excludes === null) self::buildExcludes();

        $absPath = str_replace('\\', '/', $absPath);

        foreach (self::$excludes['prefixes'] as $p)
            if ($absPath === $p || strncmp($absPath, $p.'/', strlen($p) + 1) === 0)
                return true;

        foreach (self::$excludes['regexes'] as $r)
            if (preg_match($r, $absPath) === 1)
                return true;

        return false;
    }

    private static function pluralIndex(int $n, string $locale): int {
        $rules = static::config('plural');
        if (isset($rules[$locale]) && is_callable($rules[$locale]))
            return max(0, (int)$rules[$locale]($n));

        $n = abs($n);

        switch ($locale) {
            case 'ru':
            case 'uk':
            case 'be':
                return ($n % 10 === 1 && $n % 100 !== 11) ? 0 : (($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20)) ? 1 : 2);
            default:
                return $n === 1 ? 0 : 1;
        }
    }

    private static function placeholders(array $params): array {
        $map = [];
        foreach ($params as $name => $value) {
            $name  = (string)$name;
            $value = (string)$value;

            $map[':'.$name] = $value;
            $map[':'.self::mbUcfirst($name)]  = self::mbUcfirst($value);
            $map[':'.mb_strtoupper($name)]    = mb_strtoupper($value);
        }
        return $map;
    }

    private static function mbUcfirst(string $value): string {
        return $value === '' ? '' : mb_strtoupper(mb_substr($value, 0, 1)).mb_substr($value, 1);
    }
}
