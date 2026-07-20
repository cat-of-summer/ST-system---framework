<?php

namespace ST_system;

use ST_system\Traits\HasConfig;
use ST_system\Traits\Events\HasStaticEvents;
use ST_system\Cache\CacheManager;

final class Lang {

    use HasConfig {
        setConfig as private traitSetConfig;
        config     as private traitConfig;
    }

    use HasStaticEvents;

    private function __construct() {}

    protected static function getReservedEvents(): array {
        return ['source_call'];
    }

    protected static function getDefaultConfig(): array {
        return [
            'dir'        => '~',
            'source'     => [],
            'locale'     => 'en',
            'fallback'   => 'en',
            'extensions' => ['php', 'json'],
            'dir_name'   => '',
            'cache' => [
                'use'    => true,
                'driver' => 'filesystem',
                'dir'    => Main::glue([CacheManager::config('default.dir'), Main::basename(static::class)], '/'),
            ],
            'plural' => [],
        ];
    }

    private const RESERVED = [
        'get', 'set', 'has', 'choice', 'config', 'setConfig', 'applyConfig',
        'purge', 'getUsedFiles', 'sources',
        'on', 'trigger', 'lockLocale', 'registerViewEvents',
    ];

    private const STRUCTURAL = ['dir', 'source', 'extensions', 'dir_name', 'cache'];

    private const ABSENT = "\0__lang_absent__";

    private static ?array $sources     = null;
    private static ?array $systemPaths = null;
    private static array  $maps        = [];
    private static array  $merged      = [];
    private static array  $mapDeps     = [];
    private static array  $fileData    = [];
    private static array  $runtime     = [];
    private static array  $usedFiles   = [];
    private static array  $recording   = [];
    private static array  $found       = [];
    private static array  $resolving   = [];
    private static bool   $localeLocked = false;
    private static bool   $view_events_registered = false;

    public static function lockLocale(?string $locale = null): void {
        if (self::$localeLocked)
            throw new \LogicException(__CLASS__.": локаль уже заблокирована через lockLocale()");

        if ($locale !== null)
            self::traitSetConfig(['locale' => $locale]);

        self::traitSetConfig(['locale' => self::config('locale')]);

        self::$localeLocked = true;
    }

    public static function setConfig($key = [], $value = null): void {
        $flat = is_string($key) ? [$key => $value] : Main::dotFlatten((array)$key);

        if (self::$localeLocked && array_key_exists('locale', $flat))
            throw new \LogicException(__CLASS__."::setConfig(): локаль заблокирована через lockLocale() и не может быть переопределена");

        foreach ($flat as $k => $_) {
            $root = ($p = strpos($k, '.')) !== false ? substr($k, 0, $p) : $k;

            if (in_array($root, self::STRUCTURAL, true)) {
                self::$sources     = null;
                self::$systemPaths = null;
                self::$maps        = [];
                self::$merged      = [];
                self::$mapDeps     = [];
                self::$fileData    = [];
                self::$found       = [];
                break;
            }
        }

        self::traitSetConfig($flat);
    }

    public static function config(string $key = '') {
        $value = self::traitConfig($key);

        return ($key === 'locale' || $key === 'fallback')
            ? self::resolveLocaleValue($value, $key)
            : $value;
    }

    private static function resolveLocaleValue($value, string $key): string {
        if ($value instanceof \Closure || (is_callable($value) && !is_string($value))) {
            if (isset(self::$resolving[$key]))
                throw new \LogicException(__CLASS__.": рекурсия при резолве config('{$key}')");

            self::$resolving[$key] = true;
            try {
                $value = $value();
            } finally {
                unset(self::$resolving[$key]);
            }
        }

        return (string)$value;
    }

    public static function __callStatic(string $name, array $args) {
        if (in_array($name, self::RESERVED, true) || !isset(self::sources()[$name]))
            throw new \BadMethodCallException("Method ".__CLASS__."::{$name}() not found");

        $key = (string)array_shift($args);

        self::fire('source_call', $name, $key);

        return self::get($name.':'.$key, ...$args);
    }

    public static function get(string $key, array $params = [], $default = null, ?string $locale = null) {
        $locale = $locale ?? static::config('locale');
        $value  = self::find($key, $locale, $isScope);

        if (($fb = static::config('fallback')) !== '' && $fb !== $locale) {
            $fbValue = self::find($key, $fb, $fbIsScope);

            if ($isScope && $fbIsScope)
                $value = self::$merged[$key."\0".$locale."\0".$fb] ??= self::mergeTree($fbValue, $value);
            elseif ($value === self::ABSENT)
                $value = $fbValue;
        }

        if ($value === self::ABSENT)
            $value = $default ?? $key;

        return is_string($value) && $params ? strtr($value, self::placeholders($params)) : $value;
    }

    public static function choice(string $key, $number, array $params = [], $default = null, ?string $locale = null): string {
        $locale = $locale ?? static::config('locale');
        $value  = self::find($key, $locale);
        $used   = $locale;

        if ($value === self::ABSENT && ($fb = static::config('fallback')) !== '' && $fb !== $locale) {
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
        $locale = $locale ?? static::config('locale');

        if (self::find($key, $locale) !== self::ABSENT) return true;

        $fb = static::config('fallback');
        return $fb !== '' && $fb !== $locale && self::find($key, $fb) !== self::ABSENT;
    }

    public static function set($key, $value = null, ?string $locale = null): void {
        $locale = $locale ?? static::config('locale');
        $flat   = is_array($key) ? Main::dotFlatten($key) : [(string)$key => $value];

        foreach ($flat as $k => $v)
            self::$runtime[$locale][$k] = $v;

        self::$found  = [];
        self::$merged = [];
    }

    public static function registerViewEvents(): void {
        if (self::$view_events_registered) return;
        self::$view_events_registered = true;

        View::on('cache_key', static function (array &$parts) {
            $parts['lang'] = [self::config('locale'), self::runtimeStamp()];
        });

        View::on('cache_open',  static function () { self::record(); });
        View::on('cache_close', static function (array &$deps, array &$payload) {
            $paths = self::stopRecording();
            if ($paths) $deps = array_merge($deps, $paths);
        });
    }

    private static function runtimeStamp(): string {
        return self::$runtime ? Main::hash(self::$runtime) : '';
    }

    public static function purge(): void {
        $seen = [];

        foreach (self::sources() as $s) {
            $driver = (string)($s['cache']['driver'] ?? '');
            $dir    = (string)($s['cache']['dir'] ?? '');

            if ($dir === '' || isset($seen[$k = $driver."\0".$dir])) continue;
            $seen[$k] = true;

            CacheManager::make('', ['driver' => $driver, 'dir' => $dir])->purgeBase();
        }

        self::$maps     = [];
        self::$merged   = [];
        self::$mapDeps  = [];
        self::$fileData = [];
        self::$found    = [];
    }

    public static function getUsedFiles(): array {
        return array_keys(self::$usedFiles);
    }

    private static function record(): void {
        self::$recording[] = [];
    }

    private static function stopRecording(): array {
        return self::$recording ? array_values(array_unique(array_pop(self::$recording))) : [];
    }

    private static function recordDeps(array $deps, array $files): void {
        foreach ($files as $f) self::$usedFiles[$f] = true;

        if (!self::$recording) return;

        $i = array_key_last(self::$recording);
        foreach ($deps as $p) self::$recording[$i][] = $p;
    }

    private static function find(string $key, string $locale, ?bool &$isScope = null) {
        $isScope = false;

        if (isset(self::$runtime[$locale]) && array_key_exists($key, self::$runtime[$locale]))
            return self::$runtime[$locale][$key];

        [$sourceName, $path] = self::splitKey($key);

        if (!isset(self::sources()[$sourceName]))
            throw new \RuntimeException(__CLASS__.": источник '{$sourceName}' не найден");

    
        $map = self::fullMap($sourceName, $locale);

        $memoKey = $sourceName."\0".$locale."\0".$path;
        if (array_key_exists($memoKey, self::$found)) {
            [$value, $isScope] = self::$found[$memoKey];
            return $value;
        }

        $value = self::resolve($sourceName, $path, $locale, $map, $isScope);

        self::$found[$memoKey] = [$value, $isScope];

        return $value;
    }

    private static function resolve(string $sourceName, string $path, string $locale, array $map, ?bool &$isScope) {
        $isScope  = false;
        $segments = self::pathSegments($path);

        for ($j = 0, $n = count($segments); $j < $n; $j++) {
            $candidate = $j === 0 ? $path : implode('.', array_slice($segments, $j));

            $full = $sourceName.':'.$candidate;
            if (isset(self::$runtime[$locale]) && array_key_exists($full, self::$runtime[$locale]))
                return self::$runtime[$locale][$full];

            $value = self::mapLookup($map, $candidate);
            if ($value !== self::ABSENT) return $value;
        }

        $tree = self::scopeTree($sourceName, $path, $locale, $map);
        if ($tree === self::ABSENT) return self::ABSENT;

        $isScope = true;
        return $tree;
    }

    private static function pathSegments(string $path): array {
        return $path === '' ? [] : explode('.', $path);
    }

    private static function splitKey(string $key): array {
        $p = strpos($key, ':');
        return $p === false ? ['~', $key] : [substr($key, 0, $p), substr($key, $p + 1)];
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

        return self::ABSENT;
    }

    private static function scopeTree(string $sourceName, string $path, string $locale, array $map) {
        $acc      = '';
        $prefixes = [''];
        foreach (self::pathSegments($path) as $seg) {
            $acc = $acc === '' ? $seg : $acc.'.'.$seg;
            $prefixes[] = $acc;
        }

        $tree    = [];
        $matched = false;
        foreach ($prefixes as $prefix) {
            $own = self::ownPhrases($map, $prefix);
            if ($own !== []) { $matched = true; $tree = self::mergeTree($tree, $own); }
        }

        $runtime = self::runtimeTree($sourceName, $path, $locale);

        if ($path !== '' && !$matched && !$runtime) return self::ABSENT;

        if ($runtime) $tree = self::mergeTree($tree, $runtime);

        return $tree === [] ? self::ABSENT : $tree;
    }

    private static function ownPhrases(array $map, string $prefix): array {
        $out = [];

        if ($prefix === '') {
            foreach ($map as $k => $v)
                if (strpos($k, '.') === false) $out[$k] = $v;

            return $out;
        }

        $pfx = $prefix.'.';
        $len = strlen($pfx);

        foreach ($map as $k => $v) {
            if (strncmp($k, $pfx, $len) !== 0) continue;
            if (strpos($k, '.', $len) !== false) continue;
            $out[substr($k, $len)] = $v;
        }

        return $out;
    }

    private static function runtimeTree(string $sourceName, string $path, string $locale): array {
        $pfx = $sourceName === '~' ? '' : $sourceName.':';
        if ($path !== '') $pfx .= $path.'.';

        $len  = strlen($pfx);
        $tree = [];

        foreach (self::$runtime[$locale] ?? [] as $k => $v) {
            if ($len !== 0 && strncmp($k, $pfx, $len) !== 0) continue;

            $rest = substr($k, $len);

            if ($rest === '' || ($len === 0 && strpos($rest, ':') !== false)) continue;

            Main::dotSet($tree, $rest, $v);
        }

        return $tree;
    }

    private static function mergeTree(array $base, array $over): array {
        foreach ($over as $k => $v) {
            $base[$k] = (
                array_key_exists($k, $base)
                && is_array($base[$k]) && is_array($v)
                && !Main::arrayIsList($base[$k]) && !Main::arrayIsList($v)
            )
                ? self::mergeTree($base[$k], $v)
                : $v;
        }

        return $base;
    }

    private static function fullMap(string $sourceName, string $locale): array {
        $sources = self::sources();
        if (!isset($sources[$sourceName]))
            throw new \RuntimeException(__CLASS__.": источник '{$sourceName}' не найден");

        if (!empty($sources[$sourceName]['cache']['use']))
            return self::compiledMap($sourceName, $locale);

        $memoKey = $sourceName."\0".$locale;

        if (isset(self::$maps[$memoKey])) {
            [$deps, $files] = self::$mapDeps[$memoKey];
            self::recordDeps($deps, $files);
            return self::$maps[$memoKey];
        }

        [$map, $deps, $files] = self::buildMap($sources[$sourceName], $locale);

        self::$mapDeps[$memoKey] = [$deps, $files];
        self::recordDeps($deps, $files);

        return self::$maps[$memoKey] = $map;
    }

    private static function compiledMap(string $sourceName, string $locale): array {
        $memoKey = $sourceName."\0".$locale;

        if (isset(self::$maps[$memoKey])) {
            [$deps, $files] = self::$mapDeps[$memoKey];
            self::recordDeps($deps, $files);
            return self::$maps[$memoKey];
        }

        $source = self::sources()[$sourceName];

        $cache = CacheManager::make([
            'c' => __CLASS__,
            's' => $sourceName,
            'l' => $locale,
            'd' => $source['dir'],
            'e' => implode(',', $source['ext']),
            'n' => $source['dir_name'],
        ], [
            'driver' => $source['cache']['driver'],
            'dir'    => $source['cache']['dir'],
            'ttl'    => -1,
        ]);

        $meta = $cache->getMeta();

        if (is_array($meta['deps'] ?? null) && isset($meta['stamp']) && $meta['stamp'] === self::depStamp($meta['deps'])) {
            $map = $cache->get();
            if (is_array($map)) {
                $files = (array)($meta['files'] ?? []);
                self::$mapDeps[$memoKey] = [$meta['deps'], $files];
                self::recordDeps($meta['deps'], $files);
                return self::$maps[$memoKey] = $map;
            }
        }

        [$map, $deps, $files] = self::buildMap($source, $locale);

        $cache->set($map, -1, '', [
            'stamp' => self::depStamp($deps),
            'deps'  => $deps,
            'files' => $files,
        ]);

        self::$mapDeps[$memoKey] = [$deps, $files];
        self::recordDeps($deps, $files);

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
                    if (self::isSystemPath($path)) continue;

                    if ($dirName !== '' && $item === $dirName)
                        $queue[] = [$path, $prefix, $depth, true];
                    elseif ($dirName === '' || !$isLang)
                        $queue[] = [$path, $prefix === '' ? $item : $prefix.'.'.$item, $depth + 1, $dirName === ''];
                } elseif ($isLang && isset($names[$item])) {
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

        $root = Main::preparePath(((string)static::config('dir')) ?: '~');

        $source = static::config('source');
        $source = is_array($source)
            ? $source
            : (($source === '' || $source === null) ? [] : ['~' => (string)$source]);

        if (!isset($source['~']))
            $source = ['~' => '.'] + $source;

        $map = [];
        foreach ($source as $name => $spec) {
            $name = (string)$name;
            if ($name === '' || in_array($name, self::RESERVED, true))
                throw new \LogicException(__CLASS__.": источник '{$name}' конфликтует с зарезервированным методом");

            if (is_array($spec)) {
                $dir      = (string)($spec['path'] ?? '');
                $override = $spec;
                unset($override['path']);
            } else {
                $dir      = (string)$spec;
                $override = [];
            }

            if ($dir === '') continue;

            $rel = ltrim($dir, '/~');
            if ($rel === '') $rel = '.';

            try {
                $abs = Main::preparePath($rel, $root, true);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException(
                    __CLASS__.": источник '{$name}' выходит за пределы dir: '..' в пути '{$dir}' запрещён", 0, $e
                );
            }

            $map[$name] = [
                'dir'      => $abs,
                'ext'      => self::extList($override['extensions'] ?? null),
                'dir_name' => trim((string)($override['dir_name'] ?? static::config('dir_name')), '/'),
                'cache'    => Main::merge((array)static::config('cache'), (array)($override['cache'] ?? [])),
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

    private static function isSystemPath(string $absPath): bool {
        if (self::$systemPaths === null) {
            $paths = [];

            foreach (self::sources() as $s) {
                $d = (string)($s['cache']['dir'] ?? '');
                if ($d !== '') $paths[Main::preparePath($d)] = true;
            }

            $d = (string)CacheManager::config('default.dir');
            if ($d !== '') $paths[Main::preparePath($d)] = true;

            self::$systemPaths = array_keys($paths);
        }

        $absPath = str_replace('\\', '/', $absPath);

        foreach (self::$systemPaths as $p)
            if ($absPath === $p || strncmp($absPath, $p.'/', strlen($p) + 1) === 0)
                return true;

        return false;
    }

    private static function pluralIndex(int $n, string $locale): int {
        $rules = static::config('plural');
        if (isset($rules[$locale]) && is_callable($rules[$locale]))
            return max(0, (int)$rules[$locale]($n));

        return Main::pluralIndex($n, $locale);
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
