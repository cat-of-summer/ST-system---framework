<?php

namespace ST_system;

use ST_system\Traits\HasConfig;
use ST_system\Cache\CacheManager;

final class View {

    use HasConfig {
        setConfig as private traitSetConfig;
    }

    protected static function getDefaultConfig(): array {
        return [
            'source'    => [],
            'extension' => 'php',
            'exclude'   => [],
            'cache' => [
                'use'    => false,
                'driver' => 'filesystem',
                'dir'    => Main::glue([CacheManager::config('default.dir'), Main::basename(static::class)], '/'),
                'key'    => null,
            ],
            'cascade' => false,
        ];
    }

    private const RESERVED = ['template', 'get', 'set', 'slot', 'capture', 'config', 'setConfig', 'applyConfig', 'sources', 'name', 'path', 'cache', 'cascade'];

    private const MAX_DEPTH = 50;

    private const ABSENT = "\0__view_ctx_absent__";

    private static array  $frames     = [];
    private static array  $globals    = [];
    private static ?array $sources    = null;
    private static ?array $excludes   = null;
    private static array  $boundaries = [];
    private static ?array $compose    = null;
    private static ?array $directives     = null;
    private static array  $directiveLayer = [];

    private string $name;
    private string $file;
    private array  $props;
    private        $children;
    private array  $config;
    private array  $bundle;
    private array  $parentBundle;
    private array  $override    = [];
    private        $keyExtra    = self::ABSENT;
    private bool   $auto_echo   = false;
    private bool   $rendered    = false;

    private function __construct(string $name, string $file, array $props, $children) {
        $this->name         = $name;
        $this->file         = $file;
        $this->props        = $props;
        $this->children     = $children;

        $this->parentBundle = self::$frames
            ? (self::$frames[array_key_last(self::$frames)]['bundle'] ?? [])
            : [];
        $this->recomputeConfig();
    }

    private function recomputeConfig(): void {
        $sourceKey    = (($p = strpos($this->name, ':')) !== false) ? substr($this->name, 0, $p) : $this->name;
        $sourceConfig = self::sources()[$sourceKey]['config'] ?? [];

        $this->config = Main::merge(static::config(), $sourceConfig, self::directivesFor($this->file), $this->parentBundle, $this->override);

        $this->bundle = Main::dotGet($this->config, 'cascade', false)
            ? Main::merge($this->parentBundle, $this->override)
            : [];
    }

    public static function __callStatic(string $name, array $args) {
        if (in_array($name, self::RESERVED, true) || !isset(self::sources()[$name]))
            throw new \BadMethodCallException("Method ".__CLASS__."::{$name}() not found");

        if (isset(self::sources()[$name]['file']))
            return self::template($name, ...$args);

        $view = (string)array_shift($args);

        return self::template($name.'/'.$view, ...$args);
    }

    public static function template(string $path, ...$args): self {
        $path  = trim(str_replace('\\', '/', $path), '/');
        $slash = strpos($path, '/');
        $key   = $slash === false ? $path : substr($path, 0, $slash);
        $view  = $slash === false ? ''    : substr($path, $slash + 1);

        $sources = self::sources();
        if ($key === '' || !isset($sources[$key]))
            throw new \RuntimeException(__CLASS__.": source '{$key}' not found");

        if (isset($args[0]) && !is_array($args[0])) {
            $props    = [];
            $children = $args[0];
        } else {
            $props    = (array)($args[0] ?? []);
            $children = $args[1] ?? null;
        }

        $normalized = [];
        foreach (Main::dotFlatten($props) as $k => $value)
            Main::dotSet($normalized, $k, $value);

        $source = $sources[$key];

        if (isset($source['file'])) {
            if ($view !== '')
                throw new \RuntimeException(__CLASS__.": source '{$key}' points to a file and takes no sub-view ('{$path}')");
            $file = $source['file'];
            $name = $key;
        } else {
            if ($view === '')
                throw new \RuntimeException(__CLASS__.": empty view path in '{$path}'");

            $file = null;
            foreach ($source['ext'] as $ext) {
                foreach ([
                    "{$source['dir']}/{$view}.{$ext}",
                    "{$source['dir']}/{$view}/index.{$ext}",
                ] as $candidate) {
                    $candidate = Main::preparePath($candidate, 0, true);
                    if (is_file($candidate) && !self::isExcluded($candidate)) { $file = $candidate; break 2; }
                }
            }
            if ($file === null)
                throw new \RuntimeException(__CLASS__.": view '{$view}' not found in {$source['dir']}");

            $name = $key.':'.$view;
        }

        $instance = new self($name, $file, $normalized, $children);
        $instance->auto_echo = !empty(self::$frames);

        return $instance;
    }

    private static function sources(): array {
        if (self::$sources !== null) return self::$sources;

        self::buildExcludes();

        $defaultExt = self::extList(static::config('extension'), ['php']);
        $map        = [];

        $add = static function (string $name, $spec) use (&$map, $defaultExt): void {
            if ($name === '' || in_array($name, self::RESERVED, true))
                throw new \LogicException(__CLASS__.": source '{$name}' collides with a reserved method");

            if (is_array($spec)) {
                $dir      = (string)($spec['path'] ?? '');
                $override = $spec;
                unset($override['path']);
            } else {
                $dir      = (string)$spec;
                $override = [];
            }

            if ($dir === '') return;

            $abs = Main::preparePath($dir);
            if (isset(self::$excludes['names'][$name]) || self::isExcluded($abs)) return;

            if (is_file($abs)) {
                $map[$name] = ['file' => $abs, 'config' => $override];
                return;
            }

            $map[$name] = [
                'dir'    => $abs,
                'ext'    => self::extList($override['extension'] ?? null, $defaultExt),
                'config' => $override,
            ];
        };

        $source = static::config('source');

        if (is_string($source) && $source !== '') {
            foreach (glob(Main::preparePath($source).'/*', GLOB_ONLYDIR) ?: [] as $dir)
                $add(basename($dir), $dir);
        } elseif (is_array($source)) {
            foreach ($source as $name => $spec)
                $add((string)$name, $spec);
        }

        return self::$sources = $map;
    }

    private static function buildExcludes(): void {
        if (self::$excludes !== null) return;

        $raw = static::config('exclude');
        $raw = is_array($raw) ? $raw : (($raw === '' || $raw === null) ? [] : [$raw]);

        $names = $prefixes = [];
        foreach ($raw as $e) {
            $e = (string)$e;
            if ($e === '') continue;
            if (strpbrk($e, '/~\\') !== false) $prefixes[] = Main::preparePath($e);
            else                               $names[$e]  = true;
        }

        self::$excludes = ['names' => $names, 'prefixes' => $prefixes];
    }

    private static function isExcluded(string $absPath): bool {
        if (self::$excludes === null) self::buildExcludes();
        foreach (self::$excludes['prefixes'] as $p)
            if ($absPath === $p || strncmp($absPath, $p.'/', strlen($p) + 1) === 0)
                return true;
        return false;
    }

    public static function setConfig($key = [], $value = null): void {
        $flat = is_string($key) ? [$key => $value] : Main::dotFlatten((array)$key);

        if (self::$frames) {
            $config = [];
            foreach ($flat as $k => $v) Main::dotSet($config, $k, $v);

            $i = array_key_last(self::$frames);
            self::$frames[$i]['directives'] = Main::merge(self::$frames[$i]['directives'] ?? [], $config);
            return;
        }

        self::traitSetConfig($flat);
    }

    public function cache($mode = true): self {
        return $this->applyOverride(['cache' => ['use' => $mode]]);
    }

    public function cascade(bool $on = true): self {
        return $this->applyOverride(['cascade' => $on]);
    }

    private function applyOverride(array $override): self {
        if ($this->rendered)
            throw new \LogicException(__CLASS__.': cache toggles must be called before render');
        $this->override = Main::merge($this->override, $override);
        $this->recomputeConfig();
        return $this;
    }

    private function output(): string {
        $top = empty(self::$frames);
        if ($top) Assets::beginOrchestration();

        try {
            $mode   = Main::dotGet($this->config, 'cache.use', false);
            $cached = ($mode === true || $mode === 'full');

            if ($top) {
                $html = $cached ? $this->renderComposedRoot() : $this->build();
            } elseif ($cached) {
                $html = $this->renderCachedSkeleton();
            } else {
                if (self::$compose !== null) self::$compose['composable'] = false;
                $html = $this->build();
            }

            return $top ? Assets::finalize($html) : $html;
        } finally {
            if ($top) Assets::endOrchestration();
        }
    }

    private function cacheHandle(): CacheManager {
        return CacheManager::make([
            'c' => __CLASS__,
            'n' => $this->name,
            'p' => $this->props,
            'k' => $this->keyExtra(),
        ], [
            'driver' => (string) Main::dotGet($this->config, 'cache.driver', 'filesystem'),
            'dir'    => (string) Main::dotGet($this->config, 'cache.dir', ''),
            'ttl'    => -1,
        ]);
    }

    private function keyExtra() {
        if ($this->keyExtra === self::ABSENT)
            $this->keyExtra = self::resolveKeyExtra(Main::dotGet($this->config, 'cache.key', null));

        return $this->keyExtra;
    }

    private static function resolveKeyExtra($value) {
        if ($value instanceof \Closure || (is_callable($value) && !is_string($value) && !is_array($value)))
            return self::resolveKeyExtra($value());

        if (is_array($value))
            return array_map([self::class, 'resolveKeyExtra'], $value);

        return $value;
    }

    private static function directiveCache(): CacheManager {
        return CacheManager::make([__CLASS__, '__directives'], [
            'driver' => (string) static::config('cache.driver'),
            'dir'    => (string) static::config('cache.dir'),
            'ttl'    => -1,
        ]);
    }

    private static function directiveRegistry(): array {
        if (self::$directives !== null) return self::$directives;
        $data = self::directiveCache()->get();
        return self::$directives = is_array($data) ? $data : [];
    }

    private static function directivesFor(string $file): array {
        if (array_key_exists($file, self::$directiveLayer)) return self::$directiveLayer[$file];

        $entry = self::directiveRegistry()[$file] ?? null;
        $valid = is_array($entry)
            && is_array($entry['config'] ?? null)
            && (int)($entry['stamp'] ?? -1) === (is_file($file) ? (int) filemtime($file) : 0);

        return self::$directiveLayer[$file] = $valid ? $entry['config'] : [];
    }

    private static function storeDirectives(string $file, array $config): void {
        $registry = self::directiveRegistry();
        $existing = $registry[$file] ?? null;

        if (!$config && $existing === null) { self::$directiveLayer[$file] = []; return; }

        $stamp = is_file($file) ? (int) filemtime($file) : 0;
        if (is_array($existing)
            && (int)($existing['stamp'] ?? -1) === $stamp
            && Main::hash($existing['config'] ?? null) === Main::hash($config)
        ) { self::$directiveLayer[$file] = $config; return; }

        if ($config) $registry[$file] = ['stamp' => $stamp, 'config' => $config];
        else         unset($registry[$file]);

        self::$directives = $registry;
        self::$directiveLayer[$file] = $config;
        self::directiveCache()->set($registry, -1);
    }

    private function renderComposedRoot(): string {
        $cache = $this->cacheHandle();

        $cmeta = $cache->getMeta('composed');
        if (is_array($cmeta['deps'] ?? null)
            && ($cmeta['stamp'] ?? null) === self::depStamp($cmeta['deps'])
            && $this->composedReadsValid($cmeta)
        ) {
            $html = $cache->get('composed');
            if ($html !== null) {
                if (is_array($cmeta['assets'] ?? null)) Assets::replay($cmeta['assets']);
                foreach ((array)($cmeta['sets'] ?? []) as $k => $v)
                    Main::dotSet(self::$globals, $k, $v);
                return (string) $html;
            }
        }

        $prev = self::$compose;
        self::$compose = ['composable' => true, 'descendants' => [], 'deps' => [], 'sets' => [], 'assets' => []];
        try {
            $assembled = $this->renderCachedSkeleton();
            $c = self::$compose;
        } finally {
            self::$compose = $prev;
        }

        if (!empty($c['composable'])) {
            $deps = array_keys($c['deps']);
            $cache->set($assembled, -1, 'composed', [
                'stamp'       => self::depStamp($deps),
                'deps'        => $deps,
                'descendants' => $c['descendants'],
                'sets'        => $c['sets'],
                'assets'      => $c['assets'],
            ]);
        }

        return $assembled;
    }

    private function composedReadsValid(array $cmeta): bool {
        $descendants = $cmeta['descendants'] ?? null;
        if (!is_array($descendants)) return false;

        $savedGlobals = self::$globals;
        self::$frames[] = [
            'props'    => $this->props,
            'children' => null,
            'name'     => $this->name,
            'bundle'   => [],
            'file'     => $this->file,
        ];
        foreach ((array)($cmeta['sets'] ?? []) as $k => $v)
            Main::dotSet(self::$globals, $k, $v);

        try {
            foreach ($descendants as $d)
                if (!self::readsValid((array)($d['reads_keys'] ?? []), (string)($d['reads_stamp'] ?? '')))
                    return false;
            return true;
        } finally {
            array_pop(self::$frames);
            self::$globals = $savedGlobals;
        }
    }

    private static function composeCollect(array $deps, array $readsKeys, string $readsStamp, array $sets, array $assets): void {
        if (self::$compose === null) return;

        self::$compose['descendants'][] = [
            'reads_keys'  => $readsKeys,
            'reads_stamp' => $readsStamp,
        ];
        foreach ($deps as $f)         self::$compose['deps'][$f]  = true;
        foreach ($sets as $k => $v)   self::$compose['sets'][$k]  = $v;
        foreach ($assets as $op)      self::$compose['assets'][]  = $op;
    }

    private function build(): string {
        if (count(self::$frames) >= self::MAX_DEPTH)
            throw new \RuntimeException(__CLASS__.": render depth exceeded at '{$this->name}'");

        if (self::$boundaries)
            self::$boundaries[array_key_last(self::$boundaries)]['deps'][$this->file] = true;

        self::$frames[] = [
            'props'      => $this->props,
            'children'   => $this->children,
            'name'       => $this->name,
            'bundle'     => $this->bundle,
            'file'       => $this->file,
            'directives' => [],
        ];

        ob_start();

        try {
            (static function ($__file, $props) { require $__file; })($this->file, $this->props);
        } finally {
            $html  = ob_get_clean();
            $frame = array_pop(self::$frames);
        }

        if (!empty($frame['directives'])) {
            self::storeDirectives($this->file, $frame['directives']);
            $this->recomputeConfig();

            if (self::$boundaries) {
                $mode    = Main::dotGet($this->config, 'cache.use', false);
                $touched = Main::dotGet($frame['directives'], 'cache.use', self::ABSENT) !== self::ABSENT;
                if ($touched && $mode !== true && $mode !== 'full')
                    self::$boundaries[array_key_last(self::$boundaries)]['poisoned'] = true;
            }
        }

        return $html;
    }

    private static function isSerializable($value): bool {
        try {
            \serialize($value);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function renderCachedSkeleton(): string {
        $cache = $this->cacheHandle();

        $meta      = $cache->getMeta();
        $deps      = $meta['deps'] ?? null;
        $skeleton  = null;
        $payload   = null;
        $fromCache = false;

        if (is_array($deps)
            && ($meta['stamp'] ?? null) === self::depStamp($deps)
            && self::readsValid($meta['reads_keys'] ?? [], $meta['reads_stamp'] ?? '')
        ) {
            $cached = $cache->get();
            if ($cached !== null) {
                $skeleton  = (string) $cached;
                $payload   = is_string($meta['payload'] ?? null) ? $meta['payload'] : '';
                $fromCache = true;
            }
        }

        if ($fromCache) {
            $nodeDeps       = is_array($deps) ? $deps : [];
            $nodeReadsKeys  = (array)($meta['reads_keys'] ?? []);
            $nodeReadsStamp = (string)($meta['reads_stamp'] ?? '');
        }

        if ($skeleton === null) {
            self::$boundaries[] = [
                'deps'     => [],
                'holes'    => [],
                'salt'     => Main::uuid(),
                'n'        => 0,
                'mode'     => Main::dotGet($this->config, 'cache.use', false),
                'reads'    => [],
                'sets'     => [],
                'base'     => count(self::$frames),
                'poisoned' => false,
            ];
            
            Assets::record();
            Lang::record();

            try {
                $skeleton = $this->build();
            } finally {
                $assetOps  = Assets::stopRecording();
                $extraDeps = Lang::stopRecording();
                $b = array_pop(self::$boundaries);
            }

            $holes    = $b['holes'];
            $reads    = $b['reads'];
            $sets     = $b['sets'];
            $poisoned = !empty($b['poisoned']);

            if (!$poisoned) {
                try {
                    $payload = base64_encode(\serialize(['holes' => $holes, 'sets' => $sets, 'assets' => $assetOps]));
                } catch (\Throwable $e) {
                    $poisoned = true;
                }
            }

            if ($poisoned) {
                if (self::$compose !== null) self::$compose['composable'] = false;
                return $this->fillHoles($skeleton, $holes, $sets);
            }

            $files = array_values(array_unique(array_merge(array_keys($b['deps']), $extraDeps)));
            $cache->set($skeleton, -1, '', [
                'stamp'       => self::depStamp($files),
                'deps'        => $files,
                'payload'     => $payload,
                'reads_keys'  => array_keys($reads),
                'reads_stamp' => Main::hash($reads),
            ]);

            $nodeDeps       = $files;
            $nodeReadsKeys  = array_keys($reads);
            $nodeReadsStamp = Main::hash($reads);
        }

        if ($payload === null || $payload === '') {
            $holes = $sets = $assets = [];
        } else {
            $data   = \unserialize((string) base64_decode($payload), ['allowed_classes' => true]);
            $holes  = is_array($data['holes']  ?? null) ? $data['holes']  : [];
            $sets   = is_array($data['sets']   ?? null) ? $data['sets']   : [];
            $assets = is_array($data['assets'] ?? null) ? $data['assets'] : [];

            if ($fromCache)
                Assets::replay($assets);
        }

        self::composeCollect($nodeDeps ?? [], $nodeReadsKeys ?? [], $nodeReadsStamp ?? '', $sets, $assets);

        return $this->fillHoles($skeleton, $holes, $sets);
    }

    private function fillHoles(string $skeleton, array $holes, array $sets): string {
        foreach ($sets as $k => $v)
            Main::dotSet(self::$globals, $k, $v);

        if (!$holes) return $skeleton;

        if (count(self::$frames) >= self::MAX_DEPTH)
            throw new \RuntimeException(__CLASS__.": render depth exceeded at '{$this->name}'");

        self::$frames[] = [
            'props'    => $this->props,
            'children' => $this->children,
            'name'     => $this->name,
            'bundle'   => $this->bundle,
            'file'     => $this->file,
        ];

        $map = [];

        try {
            foreach ($holes as $token => $d) {
                $child = new self($d['name'], $d['file'], $d['props'], $d['children'] ?? null);
                $child->override = (array)($d['override'] ?? []);
                $child->recomputeConfig();
                $map[$token] = $child->output();
            }
        } finally {
            array_pop(self::$frames);
        }

        return strtr($skeleton, $map);
    }

    private static function depStamp(array $files): string {
        clearstatcache();

        $stamps = [];
        foreach ($files as $f)
            $stamps[$f] = file_exists($f) ? (int) filemtime($f) : 0;

        return Main::hash($stamps);
    }

    private static function readsValid(array $keys, string $stamp): bool {
        if (!$keys) return true;

        $sentinel = self::sentinel();
        $current  = [];

        foreach ($keys as $key) {
            $value = $sentinel;
            for ($i = count(self::$frames) - 1; $i >= 0; $i--) {
                $v = Main::dotGet(self::$frames[$i]['props'], $key, $sentinel);
                if ($v !== $sentinel) { $value = $v; break; }
            }
            if ($value === $sentinel)
                $value = Main::dotGet(self::$globals, $key, $sentinel);
            $current[$key] = $value === $sentinel ? self::ABSENT : $value;
        }

        return Main::hash($current) === $stamp;
    }

    public function __toString(): string {
        if ($this->rendered) return '';
        $this->rendered = true;

        if (self::$boundaries) {
            $i = array_key_last(self::$boundaries);

            if (self::$boundaries[$i]['mode'] === 'full') {
                if (!self::isSerializable($this->props))
                    self::$boundaries[$i]['poisoned'] = true;
                return $this->build();
            }

            if (self::isSerializable([$this->props, $this->children])) {
                $token = '<!--VH:'.self::$boundaries[$i]['salt'].':'.(self::$boundaries[$i]['n']++).'-->';
                self::$boundaries[$i]['holes'][$token] = [
                    'name'     => $this->name,
                    'file'     => $this->file,
                    'props'    => $this->props,
                    'children' => $this->children,
                    'override' => $this->override,
                ];
                return $token;
            }

            if (self::isSerializable($this->props)) {
                return $this->build();
            }

            self::$boundaries[$i]['poisoned'] = true;
            return $this->build();
        }

        return $this->output();
    }

    public function __destruct() {
        if ($this->auto_echo && !$this->rendered) echo $this->__toString();
    }

    public static function get(string $key, $default = null) {
        $sentinel = self::sentinel();
        $foundAt  = null;
        $value    = $sentinel;

        for ($i = count(self::$frames) - 1; $i >= 0; $i--) {
            $v = Main::dotGet(self::$frames[$i]['props'], $key, $sentinel);
            if ($v !== $sentinel) { $value = $v; $foundAt = $i; break; }
        }
        if ($value === $sentinel)
            $value = Main::dotGet(self::$globals, $key, $sentinel);

        if (self::$boundaries) {
            $b    = array_key_last(self::$boundaries);
            $base = self::$boundaries[$b]['base'];
            if (($foundAt === null || $foundAt < $base) && !array_key_exists($key, self::$boundaries[$b]['sets']))
                self::$boundaries[$b]['reads'][$key] = ($value === $sentinel ? self::ABSENT : $value);
        }

        return $value === $sentinel ? $default : $value;
    }

    public static function name(int $i = 0): string {
        $count = count(self::$frames);
        if ($count === 0) return '';
        if ($i < 0) $i += $count;
        if ($i < 0 || $i >= $count) return '';
        $name = self::$frames[$i]['name'];
        $pos  = strpos($name, ':');
        return $pos === false ? $name : substr($name, $pos + 1);
    }

    public static function path(int $i = -1): string {
        $count = count(self::$frames);
        if ($count === 0) return '';
        if ($i < 0) $i += $count;
        if ($i < 0 || $i >= $count) return '';
        $file = self::$frames[$i]['file'] ?? '';
        return $file === '' ? '' : (string) preg_replace('/\.[^.\/\\\\]+$/', '', $file);
    }

    public static function set($key, $value = null): void {
        foreach (Main::dotFlatten(is_array($key) ? $key : [$key => $value]) as $path => $v) {
            Main::dotSet(self::$globals, $path, $v);
            if (self::$boundaries)
                self::$boundaries[array_key_last(self::$boundaries)]['sets'][$path] = $v;
        }
    }

    public static function slot(...$args): void {
        if (empty(self::$frames))
            throw new \LogicException(__CLASS__.'::slot() called outside of a render');

        $name     = null;
        $defaults = [];

        if (isset($args[0]) && is_string($args[0])) {
            $name     = $args[0];
            $defaults = (array)($args[1] ?? []);
        } elseif (isset($args[0]) && is_array($args[0])) {
            $defaults = $args[0];
        }

        $top      = count(self::$frames) - 1;
        $sentinel = self::sentinel();

        foreach (Main::dotFlatten($defaults) as $path => $value)
            if (self::get($path, $sentinel) === $sentinel)
                Main::dotSet(self::$frames[$top]['props'], $path, $value);

        $children = self::$frames[$top]['children'];

        if ($name === null) {
            if (is_callable($children))
                $children();
            elseif (is_array($children) && isset($children['default']))
                $children['default']();
        } elseif (is_array($children) && isset($children[$name])) {
            $children[$name]();
        }
    }

    public static function capture(callable $fn): string {
        ob_start();
        try {
            $fn();
        } finally {
            $html = ob_get_clean();
        }
        return $html;
    }

    private static function extList($value, array $fallback): array {
        if ($value === null || $value === '' || $value === []) return $fallback;

        $list = [];
        foreach (is_array($value) ? $value : [$value] as $e) {
            $e = ltrim((string)$e, '.');
            if ($e !== '') $list[] = $e;
        }

        return $list ?: $fallback;
    }

    private static function sentinel(): object {
        static $s = null;
        if ($s === null) $s = new \stdClass();
        return $s;
    }
}
