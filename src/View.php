<?php

namespace ST_system;

use ST_system\Traits\HasConfig;

final class View {

    use HasConfig;

    protected static function getDefaultConfig(): array {
        return [
            'source'    => [],
            'extension' => 'php',
            'exclude'   => [],
        ];
    }

    // имена, занятые реальными методами: источник с таким именем недостижим через __callStatic
    private const RESERVED = ['get', 'set', 'slot', 'capture', 'config', 'setConfig', 'applyConfig', 'sources', 'name', 'render', 'toHtml'];

    // самоссылающийся компонент должен падать внятно, а не ронять стек
    private const MAX_DEPTH = 50;

    private static array  $frames  = [];   // ['props' => array, 'children' => callable|array|null, 'name' => string]
    private static array  $globals = [];   // View::set() — низший приоритет при поиске
    private static ?array $sources = null; // name => ['dir' => abs, 'ext' => string]

    private string $name;
    private string $file;
    private array  $props;
    private        $children;
    private bool   $auto_echo = false;
    private bool   $rendered  = false;

    private function __construct(string $name, string $file, array $props, $children) {
        $this->name     = $name;
        $this->file     = $file;
        $this->props    = $props;
        $this->children = $children;
    }

    public static function __callStatic(string $name, array $args) {
        if (in_array($name, self::RESERVED, true) || !isset(self::sources()[$name]))
            throw new \BadMethodCallException("Method ".__CLASS__."::{$name}() not found");

        [$view, $props, $children] = self::normalizeArgs($args);

        $instance = new self(
            $name.':'.$view,
            self::resolve(self::sources()[$name], $view),
            self::normalizeProps($props),
            $children
        );

        $instance->auto_echo = !empty(self::$frames);

        return $instance;
    }

    private static function normalizeArgs(array $args): array {
        $name = (string)array_shift($args);

        if (isset($args[0]) && !is_array($args[0]))
            return [$name, [], $args[0]];

        return [$name, (array)($args[0] ?? []), $args[1] ?? null];
    }

    private static function normalizeProps(array $props): array {
        $out = [];
        foreach (Main::dotFlatten($props) as $key => $value)
            Main::dotSet($out, $key, $value);
        return $out;
    }

    private static function sources(): array {
        if (self::$sources !== null) return self::$sources;

        $extension = (string)(static::config('extension') ?: 'php');
        $map       = [];

        $add = static function (string $name, string $dir) use (&$map, $extension): void {
            if (in_array($name, self::RESERVED, true))
                throw new \LogicException(__CLASS__.": source '{$name}' collides with a reserved method");

            $map[$name] = ['dir' => Main::preparePath($dir), 'ext' => $extension];
        };

        $source  = static::config('source');
        $exclude = (array)static::config('exclude');

        if (is_string($source) && $source !== '') {
            foreach (glob(Main::preparePath($source).'/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $name = basename($dir);
                if (in_array($name, $exclude, true)) continue;
                $add($name, $dir);
            }
        } elseif (is_array($source)) {
            foreach ($source as $name => $spec) {
                if (is_array($spec)) {
                    $dir = (string)($spec['source'] ?? '');
                    $add((string)$name, $dir);
                    if (!empty($spec['alias'])) $add((string)$spec['alias'], $dir);
                } else {
                    $add((string)$name, (string)$spec);
                }
            }
        }

        return self::$sources = $map;
    }

    private static function resolve(array $source, string $name): string {
        // Main::preparePath молча схлопывает '..' — сегменты проверяем ДО склейки
        foreach (explode('/', str_replace('\\', '/', $name)) as $segment)
            if ($segment === '' || $segment === '.' || $segment === '..')
                throw new \InvalidArgumentException(__CLASS__.": invalid view name '{$name}'");

        foreach ([
            "{$source['dir']}/{$name}.{$source['ext']}",
            "{$source['dir']}/{$name}/index.{$source['ext']}",
        ] as $candidate)
            if (is_file($candidate)) return $candidate;

        throw new \RuntimeException(__CLASS__.": view '{$name}' not found in {$source['dir']}");
    }

    private function build(): string {
        if (count(self::$frames) >= self::MAX_DEPTH)
            throw new \RuntimeException(__CLASS__.": render depth exceeded at '{$this->name}'");

        self::$frames[] = ['props' => $this->props, 'children' => $this->children, 'name' => $this->name];

        ob_start();

        try {
            (static function ($__file, $props) { require $__file; })($this->file, $this->props);
        } finally {
            $html = ob_get_clean();
            array_pop(self::$frames);
        }

        return $html;
    }

    private function output(): string {
        $html = $this->build();
        return empty(self::$frames) ? Assets::finalize($html) : $html;
    }

    public function render(): void {
        if ($this->rendered) return;
        $this->rendered = true;
        echo $this->output();
    }

    public function toHtml(): string {
        if ($this->rendered) return '';
        $this->rendered = true;
        return $this->output();
    }

    public function __toString(): string {
        return $this->toHtml();
    }

    public function __destruct() {
        if ($this->auto_echo && !$this->rendered) $this->render();
    }

    public static function get(string $key, $default = null) {
        $sentinel = self::sentinel();

        for ($i = count(self::$frames) - 1; $i >= 0; $i--) {
            $value = Main::dotGet(self::$frames[$i]['props'], $key, $sentinel);
            if ($value !== $sentinel) return $value;
        }

        $value = Main::dotGet(self::$globals, $key, $sentinel);
        return $value !== $sentinel ? $value : $default;
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

    public static function set($key, $value = null): void {
        foreach (Main::dotFlatten(is_array($key) ? $key : [$key => $value]) as $path => $v)
            Main::dotSet(self::$globals, $path, $v);
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

    private static function sentinel(): object {
        static $s = null;
        if ($s === null) $s = new \stdClass();
        return $s;
    }
}
