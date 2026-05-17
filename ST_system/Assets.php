<?php

namespace ST_system;

use ST_system\Traits\HasConfig;
use ST_system\Storage\File;

final class Assets {

    use HasConfig;

    protected static function getDefaultConfig(): array {
        return [
            'default_buffer' => 'head',
            'bufferization'  => true,
        ];
    }

    private static array $buffers = [];
    private static array $stack   = [];

    private File $file;
    private string $buffer;

    public function __construct(string $path, string $buffer = '') {
        $this->file = File::make($path);
        $this->buffer = $buffer;
    }

    public static function create(string $path, string $buffer = ''): self {
        return new static($path, $buffer);
    }

    public static function __callStatic(string $name, array $args) {
        if (method_exists(static::class, $name))
            return static::$name(...$args);

        throw new \BadMethodCallException("Method ".__CLASS__."::{$name}() not found");
    }

    public function __call(string $name, array $args) {
        switch ($name) {
            case 'mount':
            case 'render':
                if (($args[0] ?? '') === '' && $this->buffer !== '') $args[0] = $this->buffer;
                return static::$name(...$args);

            case 'finalize':
                return static::finalize(...$args);

            case 'addString':
                if (($args[1] ?? '') === '' && $this->buffer !== '') $args[1] = $this->buffer;
                return static::addString(...$args);

            case 'sprite':
            case 'svg':
            case 'addCss':
            case 'addJs':
            case 'addFont':
            case 'addResource':
                $idx = $name === 'sprite' ? 2 : 0;
                $input = $args[$idx] ?? '';
                $args[$idx] = $input === ''
                    ? $this->file->getPathname()
                    : ($this->file->isFile() ? $this->file->getDirectory() : $this->file->getPathname()).'/'.ltrim($input, '/');

                if ($name !== 'sprite' && $name !== 'svg' && ($args[2] ?? '') === '' && $this->buffer !== '')
                    $args[2] = $this->buffer;

                return static::$name(...$args);
        }

        throw new \BadMethodCallException("Method ".__CLASS__."::{$name}() not found");
    }

    private static function currentBuffer(): string {
        return end(self::$stack) ?: static::config('default_buffer');
    }

    private static function ensureBuffer(string $name): void {
        if (!isset(self::$buffers[$name]))
            self::$buffers[$name] = ['content' => '', 'started' => false, 'seen' => []];
    }

    private static function unpackBuffer(string $name): string {
        self::ensureBuffer($name);
        $content = self::$buffers[$name]['content'];
        self::$buffers[$name]['content'] = '';
        return $content;
    }

    private static function storeAsset(string $html, string $buffer): void {
        self::ensureBuffer($buffer);

        $key = md5($html);
        if (isset(self::$buffers[$buffer]['seen'][$key])) return;

        self::$buffers[$buffer]['seen'][$key] = true;
        self::$buffers[$buffer]['content'] .= $html;
    }

    private static function resolve($input): array {
        $files = [];
        foreach ((array)$input as $p) {
            if (filter_var($p, FILTER_VALIDATE_URL)) {
                $files[] = File::make($p);
                continue;
            }
            $found = File::find($p);
            $files = array_merge($files, $found ?: [File::make($p)]);
        }
        return $files;
    }

    private static function mount(string $name): void {
        self::ensureBuffer($name);

        if (self::$buffers[$name]['started'])
            throw new \RuntimeException("Buffer '{$name}' already started.");

        self::$buffers[$name]['started'] = true;
        self::$stack[] = $name;

        if (static::config('bufferization')) {
            echo self::unpackBuffer($name);
            ob_start();
        } else {
            echo '<!-- '.__CLASS__.'::mount("'.$name.'") -->';
        }
    }

    private static function render(string $buffer): void {
        if (!static::config('bufferization')) {
            array_pop(self::$stack);
            self::$buffers[$buffer]['started'] = false;
            return;
        }

        if (!in_array($buffer, self::$stack, true)) {
            if (isset(self::$buffers[$buffer]))
                echo self::unpackBuffer($buffer);
            return;
        }

        if ($buffer !== self::currentBuffer())
            throw new \RuntimeException("Buffer mismatch: trying to end '{$buffer}', but top active is '".self::currentBuffer()."'. Use LIFO order.");

        self::$buffers[$buffer]['content'] .= ob_get_clean();
        array_pop(self::$stack);
        self::$buffers[$buffer]['started'] = false;

        echo self::unpackBuffer($buffer);
    }

    private static function finalize(string $html): string {
        foreach (self::$buffers as $name => ['content' => &$content, 'started' => &$started]) {
            $html = str_replace('<!-- '.__CLASS__.'::mount("'.$name.'") -->', $content, $html);
            $started = false;
            $content = '';
        }

        return $html;
    }

    private static function addString($string, string $buffer = ''): void {
        $buffer = $buffer !== '' ? $buffer : self::currentBuffer();

        foreach ((array)$string as $s)
            self::storeAsset($s."\n", $buffer);
    }

    private static function addCss($href, array $attrs = [], string $buffer = ''): void {
        $buffer = $buffer !== '' ? $buffer : self::currentBuffer();

        foreach (self::resolve($href) as $file)
            self::storeAsset($file->toHTML($attrs)."\n", $buffer);
    }

    private static function addJs($src, array $attrs = [], string $buffer = ''): void {
        $buffer = $buffer !== '' ? $buffer : self::currentBuffer();

        foreach (self::resolve($src) as $file)
            self::storeAsset($file->toHTML($attrs)."\n", $buffer);
    }

    private static function addFont($src, array $attrs = [], string $buffer = ''): void {
        $buffer = $buffer !== '' ? $buffer : self::currentBuffer();

        foreach (self::resolve($src) as $file)
            self::storeAsset($file->toHTML($attrs), $buffer);
    }

    private static function svg(string $path, array $attrs = [], bool $return_path = false): string {
        if (pathinfo($path, PATHINFO_EXTENSION) === '') $path .= '.svg';

        $file = File::make($path);
        return $return_path ? $file->getRelativePath() : $file->extract($attrs);
    }

    private static function sprite(string $icon_id, array $attrs = [], string $path = ''): string {
        if ($path === '')
            throw new \InvalidArgumentException('Assets::sprite() requires a sprite file path.');

        if (pathinfo($path, PATHINFO_EXTENSION) === '') $path .= '.svg';

        return File::make($path)->toSprite($icon_id, $attrs);
    }

    private static function addResource($path, array $attrs = [], string $buffer = ''): void {
        $buffer = $buffer !== '' ? $buffer : self::currentBuffer();

        foreach (self::resolve($path) as $file) {
            $mime = $file->getMime();

            if (strpos($mime, 'font/') === 0)
                self::storeAsset($file->toHTML($attrs), $buffer);
            elseif ($mime === 'text/css' || $mime === 'application/javascript')
                self::storeAsset($file->toHTML($attrs)."\n", $buffer);
            elseif ($mime === 'image/svg+xml')
                self::storeAsset($file->extract($attrs), $buffer);
            else
                throw new \InvalidArgumentException("Unsupported asset mime: {$mime} ({$file->getPathname()})");
        }
    }
}
