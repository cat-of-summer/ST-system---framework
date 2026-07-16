<?php

namespace ST_system;

use ST_system\Traits\HasConfig;
use ST_system\Traits\HasEvents;
use ST_system\Traits\HasInstance;
use ST_system\Cache\CacheManager;

final class Lang {

    use HasConfig {
        setConfig as private traitSetConfig;
        config     as private traitConfig;
    }

    use HasInstance;

    use HasEvents {
        on as private _on;
    }

    private function __construct() {}

    public static function on(string $event, callable $listener): void {
        self::getInstance()->_on($event, $listener);
    }

    protected static function getReservedEvents(): array {
        return ['build_key'];
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
        'get', 'set', 'has', 'all', 'choice', 'config', 'setConfig', 'applyConfig',
        'purge', 'getUsedFiles', 'sources', 'record', 'stopRecording',
        // Публичные методы: __callStatic для них не сработает, поэтому его гард не поможет —
        // источник с таким именем упал бы с ArgumentCountError вместо внятной ошибки.
        // 'trigger' приходит из HasEvents: алиас 'on' не исключает остальные методы трейта.
        'on', 'trigger',
        // Удалены, но зарезервированы: иначе источник перехватит вызов через __callStatic
        // и вернёт строку вместо ошибки.
        'setLocale', 'getLocale', 'setFallback', 'getFallback',
    ];

    private const STRUCTURAL = ['dir', 'source', 'extensions', 'dir_name', 'cache'];

    private const ABSENT = "\0__lang_absent__";

    private static ?array $sources     = null;
    private static ?array $systemPaths = null;
    private static array  $maps        = [];
    private static array  $mapDeps     = [];
    private static array  $fileData    = [];
    private static array  $runtime     = [];
    private static array  $usedFiles   = [];
    private static array  $recording   = [];
    private static array  $found       = [];
    private static array  $resolving   = [];

    public static function setConfig($key = [], $value = null): void {
        $flat = is_string($key) ? [$key => $value] : Main::dotFlatten((array)$key);

        foreach ($flat as $k => $_) {
            $root = ($p = strpos($k, '.')) !== false ? substr($k, 0, $p) : $k;

            if (in_array($root, self::STRUCTURAL, true)) {
                self::$sources     = null;
                self::$systemPaths = null;
                self::$maps        = [];
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

        return self::get($name.':'.$key, ...$args);
    }

    public static function get(string $key, array $params = [], $default = null, ?string $locale = null) {
        $locale = $locale ?? static::config('locale');
        $value  = self::find($key, $locale);

        if ($value === self::ABSENT && ($fb = static::config('fallback')) !== '' && $fb !== $locale)
            $value = self::find($key, $fb);

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

        self::$found = [];   // мемо резолва мог зафиксировать ABSENT для перекрытого теперь ключа
    }

    public static function all(string $prefix = '', ?string $locale = null): array {
        $locale = $locale ?? static::config('locale');
        [$sourceName, $path] = self::splitKey($prefix);

        $locales = [];
        if (($fb = static::config('fallback')) !== '' && $fb !== $locale) $locales[] = $fb;
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
        $seen = [];

        foreach (self::sources() as $s) {
            $driver = (string)($s['cache']['driver'] ?? '');
            $dir    = (string)($s['cache']['dir'] ?? '');

            if ($dir === '' || isset($seen[$k = $driver."\0".$dir])) continue;
            $seen[$k] = true;

            CacheManager::make('', ['driver' => $driver, 'dir' => $dir])->purgeBase();
        }

        self::$maps     = [];
        self::$mapDeps  = [];
        self::$fileData = [];
        self::$found    = [];
    }

    public static function getUsedFiles(): array {
        return array_keys(self::$usedFiles);
    }

    // Рекордер зеркалит Assets::record()/stopRecording() — тот же контракт, что View уже
    // дёргает вокруг build(). Накопительного getUsedFiles() для этого недостаточно:
    // он множество, а не список на область, и дифф вокруг build() отдал бы файлы источника
    // только первой границе (мемо-гард в compiledMap() отмечает их ровно один раз за запрос).
    public static function record(): void {
        self::$recording[] = [];
    }

    public static function stopRecording(): array {
        return self::$recording ? array_values(array_unique(array_pop(self::$recording))) : [];
    }

    // $deps шире $files: в них есть и каталоги, потому что mtime каталога — единственный сигнал
    // о ПОЯВЛЕНИИ нового языкового файла. Рекордеру нужны они, а getUsedFiles() остаётся
    // списком именно файлов, как и задокументирован.
    private static function recordDeps(array $deps, array $files): void {
        foreach ($files as $f) self::$usedFiles[$f] = true;

        if (!self::$recording) return;

        $i = array_key_last(self::$recording);
        foreach ($deps as $p) self::$recording[$i][] = $p;
    }

    private static function find(string $key, string $locale) {
        if (isset(self::$runtime[$locale]) && array_key_exists($key, self::$runtime[$locale]))
            return self::$runtime[$locale][$key];

        [$sourceName, $path] = self::splitKey($key);

        $sources = self::sources();
        if (!isset($sources[$sourceName]))
            throw new \RuntimeException(__CLASS__.": источник '{$sourceName}' не найден");

        if ($path === '') return self::ABSENT;

        self::getInstance()->fire('build_key', $path, $sourceName, $locale);

        $source = $sources[$sourceName];

        if (empty($source['cache']['use']))
            return self::resolve($source, $sourceName, $path, $locale, null);

        // Карта берётся ДО мемо: на мемо-хите compiledMap() заново отмечает зависимости
        // источника в активном рекордере. Иначе первая граница, спросившая ключ, забирала бы
        // зависимости себе, а всем следующим доставался бы пустой список — то есть навсегда
        // устаревший HTML. Мемо кеширует только результат резолва.
        $map = self::compiledMap($sourceName, $locale);

        $memoKey = $sourceName."\0".$locale."\0".$path;
        if (array_key_exists($memoKey, self::$found)) return self::$found[$memoKey];

        return self::$found[$memoKey] = self::resolve($source, $sourceName, $path, $locale, $map);
    }

    /**
     * Наследование: фраза, объявленная в родительском каталоге, видна в дочерней области под
     * своим коротким именем. Кандидаты строятся отбрасыванием левого сегмента до упора
     * (`index.copy` -> `copy`), первое попадание побеждает. Срабатывает только после промаха,
     * поэтому ключи, которые резолвятся сейчас, резолвятся ровно так же.
     */
    private static function resolve(array $source, string $sourceName, string $path, string $locale, ?array $map) {
        $segments = explode('.', $path);

        for ($j = 0, $n = count($segments); $j < $n; $j++) {
            $candidate = $j === 0 ? $path : implode('.', array_slice($segments, $j));

            // Runtime-оверрайд по переписанному ключу: без этого set('page:index.copy') не
            // виделся бы вызовом page('copy'), который build_key переписал в 'index.copy'.
            $full = $sourceName.':'.$candidate;
            if (isset(self::$runtime[$locale]) && array_key_exists($full, self::$runtime[$locale]))
                return self::$runtime[$locale][$full];

            $value = $map !== null
                ? self::mapLookup($map, $candidate)
                : self::resolveCold($source, array_slice($segments, $j), $locale);

            if ($value !== self::ABSENT) return $value;
        }

        return self::ABSENT;
    }

    private static function splitKey(string $key): array {
        $p = strpos($key, ':');
        return $p === false ? ['~', $key] : [substr($key, 0, $p), substr($key, $p + 1)];
    }

    private static function resolveCold(array $source, array $segments, string $locale) {
        $n      = count($segments);
        $suffix = $source['dir_name'] !== '' ? '/'.$source['dir_name'] : '';

        $dirs = [$source['dir']];
        for ($i = 1; $i < $n; $i++)
            $dirs[$i] = $dirs[$i - 1].'/'.$segments[$i - 1];

        $max = 0;
        for ($i = 1; $i < $n; $i++) {
            if (!is_dir($dirs[$i])) break;
            $max = $i;
        }

        for ($i = $max; $i >= 0; $i--) {
            $dir = $dirs[$i].$suffix;

            foreach ($source['ext'] as $ext) {
                $file = $dir.'/'.$locale.'.'.$ext;

                if (!is_file($file)) continue;

                $data = self::parseFile($file);
                if ($data === null) continue;

                $value = Main::dotGet($data, implode('.', array_slice($segments, $i)), self::ABSENT);
                if ($value !== self::ABSENT) {
                    self::recordDeps([$file], [$file]);
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

        // Мемо-хит обязан отметить зависимости заново: иначе за запрос они запишутся ровно
        // один раз, и вторая граница View получила бы пустой список — то есть навсегда
        // устаревший HTML вместо лишней пересборки.
        if (isset(self::$maps[$memoKey])) {
            [$deps, $files] = self::$mapDeps[$memoKey];
            self::recordDeps($deps, $files);
            return self::$maps[$memoKey];
        }

        $source = self::sources()[$sourceName];

        // Ключ строго ассоциативный: Main::hash() сортирует элементы списка (SORT_STRING),
        // из-за чего список был бы нечувствителен к порядку. Сюда входит только то, что влияет
        // на содержимое карты — не cache.use и не сырой оверрайд (он мог бы принести Closure).
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
                $dir      = (string)($spec['source'] ?? '');
                $override = $spec;
                unset($override['source']);
            } else {
                $dir      = (string)$spec;
                $override = [];
            }

            if ($dir === '') continue;   // пустой путь = выключить источник

            // Путь источника всегда относителен dir: ведущие '/' и '~' срезаются, чтобы они не
            // уводили из корня, а '..' запрещён ($strict) — громкая ошибка честнее тихого побега.
            $rel = ltrim($dir, '/~');
            if ($rel === '') $rel = '.';   // '/', '~' => сам dir

            try {
                $abs = Main::preparePath($rel, $root, true);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException(
                    __CLASS__.": источник '{$name}' выходит за пределы dir: '..' в пути '{$dir}' запрещён", 0, $e
                );
            }

            // Оверрайды читаются инлайном: self::$sources присваивается только в return,
            // поэтому вызов чего-либо, что зовёт sources(), отсюда — бесконечная рекурсия.
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

    // Кеш-каталоги лежат внутри дерева источника (по умолчанию источник — весь document root),
    // а buildMap() кладёт каждый пройденный каталог в deps. Без этого prune запись кеша меняла бы
    // mtime каталога из собственных deps, и карта инвалидировала бы себя на каждом запросе.
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
