<?php

namespace ST_system;

use ST_system\Traits\HasConfig;
use ST_system\Storage\File;
use ST_system\Rule;
use ST_system\Storage\Mimes\ImageMime;
use ST_system\Cache\Manager as Cache;

final class Assets {

    use HasConfig;

    protected static function getDefaultConfig(): array {
        return [
            'default_buffer' => 'head',
            'bufferization'  => true,
            'css'   => ['combine' => true, 'minify' => true],
            'js'    => ['combine' => true, 'minify' => true],
            'fonts' => ['combine' => true, 'minify' => true],
        ];
    }

    private static function placeholder(string $name): string {
        return '<!-- __ASSETS__:'.$name.'__ -->';
    }

    private static array $buffers  = [];
    private static array $stack    = [];

    private File $file;
    private string $buffer;

    public function __construct(string $path, string $buffer = '') {
        $this->file = File::make($path);
        $this->buffer = $buffer ?: static::config('default_buffer');
    }

    public static function create(string $path, string $buffer = ''): self {
        return new static($path, $buffer);
    }

    public static function __callStatic(string $name, array $args) {
        switch ($name) {
            case 'mount':
            case 'render':
            case 'finalize':
            case 'addCss':
            case 'addJs':
            case 'addFont':
            case 'addResource':
            case 'addString':
            case 'setManifest':
            case 'svg':
            case 'sprite':
                return static::$name(...$args);
        }

        throw new \BadMethodCallException("Method ".__CLASS__."::{$name}() not found");
    }

    public function __call(string $name, array $args) {
        $base = $this->file->isFile() ? $this->file->getDirectory() : $this->file->getPathname();

        switch ($name) {
            case 'mount':
            case 'render':
                return static::$name($args[0] ?? $this->buffer);

            case 'finalize':
                return static::finalize(...$args);

            case 'svg':
                $args[0] = ($args[0] ?? '') !== '' ? $base.'/'.ltrim($args[0], '/') : $base;
                return static::svg(...$args);

            case 'sprite':
                $path = ($args[2] ?? '') !== '' ? $base.'/'.ltrim($args[2], '/') : $base;
                return static::sprite($path, $args[0] ?? '', $args[1] ?? []);

            case 'addCss':
            case 'addJs':
            case 'addFont':
            case 'addResource':
                $args[0] = ($args[0] ?? '') !== '' ? $base.'/'.ltrim($args[0], '/') : $base;
                if (empty($args[2])) $args[2] = $this->buffer;
                return static::$name(...$args);

            case 'addString':
            case 'setManifest':
                if (empty($args[1])) $args[1] = $this->buffer;
                return static::$name(...$args);
        }

        throw new \BadMethodCallException("Method ".__CLASS__."::{$name}() not found");
    }

    private static function currentBuffer(): string {
        return end(self::$stack) ?: static::config('default_buffer');
    }

    private static function ensureBuffer(string $name): void {
        if (!isset(self::$buffers[$name]))
            self::$buffers[$name] = [
                'content' => '',
                'started' => false,
                'seen'    => [],
                'assets'  => ['css' => [], 'js' => [], 'fonts' => []],
            ];
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
        static $is_shutdown_initialized = false;
        if (!$is_shutdown_initialized) {
            $is_shutdown_initialized = true;
            register_shutdown_function(static function () {
                while ($name = array_pop(self::$stack)) {
                    $captured = ob_get_clean();
                    $replacement = self::unpackBuffer($name) . self::buildAssetsHtml($name);
                    self::$buffers[$name]['started'] = false;
                    echo str_replace(self::placeholder($name), $replacement, $captured);
                }
            });
        }

        self::ensureBuffer($name);

        if (self::$buffers[$name]['started'])
            throw new \RuntimeException("Buffer '{$name}' already started.");

        self::$buffers[$name]['started'] = true;
        self::$stack[] = $name;

        if (static::config('bufferization')) {
            echo self::unpackBuffer($name);
            ob_start();
            echo self::placeholder($name);
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
                echo self::unpackBuffer($buffer) . self::buildAssetsHtml($buffer);
            return;
        }

        if ($buffer !== self::currentBuffer())
            throw new \RuntimeException("Buffer mismatch: trying to end '{$buffer}', but top active is '".self::currentBuffer()."'. Use LIFO order.");

        $captured = ob_get_clean();
        array_pop(self::$stack);
        self::$buffers[$buffer]['started'] = false;

        $replacement = self::unpackBuffer($buffer) . self::buildAssetsHtml($buffer);
        echo str_replace(self::placeholder($buffer), $replacement, $captured);
    }

    private static function buildAssetsHtml(string $buffer): string {
        $html = '';
        foreach (['css', 'js', 'fonts'] as $type) {
            $items = self::$buffers[$buffer]['assets'][$type] ?? [];
            if (!$items) continue;

            $groups = [];
            foreach ($items as $item)
                $groups[md5(serialize($item['attrs']))][] = $item;

            foreach ($groups as $group) {
                $files = array_column($group, 'file');
                $attrs = $group[0]['attrs'];

                $can_combine = count($files) > 1
                    && static::config("{$type}.combine")
                    && !($type === 'js' && ($attrs['type'] ?? '') === 'module');

                if ($can_combine) {
                    $out = $files[0]->combine($files, $attrs);
                    if (static::config("{$type}.minify")) $out = $out->minify();
                    $html .= $out->toHTML($attrs)."\n";
                } else {
                    foreach ($files as $f) $html .= $f->toHTML($attrs)."\n";
                }
            }
        }

        self::$buffers[$buffer]['assets'] = ['css' => [], 'js' => [], 'fonts' => []];
        return $html;
    }

    private static function push(string $type, $src, array $attrs, string $buffer): void {
        $buffer = $buffer !== '' ? $buffer : self::currentBuffer();
        self::ensureBuffer($buffer);

        foreach (self::resolve($src) as $file) {
            $key = md5($file->getPathname().'|'.serialize($attrs));
            if (isset(self::$buffers[$buffer]['seen'][$key])) continue;

            self::$buffers[$buffer]['seen'][$key] = true;
            self::$buffers[$buffer]['assets'][$type][] = ['file' => $file, 'attrs' => $attrs];
        }
    }

    private static function finalize(string $html): string {
        foreach (self::$buffers as $name => &$buf) {
            $replacement = $buf['content'] . self::buildAssetsHtml($name);
            $html = str_replace('<!-- '.__CLASS__.'::mount("'.$name.'") -->', $replacement, $html);
            $buf['started'] = false;
            $buf['content'] = '';
        }

        return $html;
    }

    private static function addString($string, string $buffer = ''): void {
        $buffer = $buffer !== '' ? $buffer : self::currentBuffer();

        foreach ((array)$string as $s)
            self::storeAsset($s."\n", $buffer);
    }

    private static function setManifest(array $params = [], string $buffer = ''): void {
        static $done = false;

        if ($done)
            throw new \LogicException(__CLASS__.'::setManifest() can be called only once per request');

        $favicon = $params['favicon'] ?? '';
        unset($params['favicon']);

        $source = File::make($favicon)->fetch();

        $cache = Cache::make([__CLASS__, 'manifest', $params, $source->getPathname()], [
            'driver' => 'filesystem',
            'dir'    => File::config('cache.dir'),
            'ttl'    => -1,
        ]);

        $html = $cache->remember(fn() => self::generateManifest($source, $params, $cache), -1);

        self::storeAsset($html, $buffer !== '' ? $buffer : self::currentBuffer());

        $done = true;
    }

    private static function generateManifest(File $source, array $params, Cache $cache): string {
        $can_ico = in_array('ico', ImageMime::getAllowedExtension(), true);

        $variants = [
            ['favicon.svg',                  'svg',                    null, null, 'icon'],
            ['favicon-96x96.png',            'png',                    96,   96,   'icon'],
            ['apple-touch-icon.png',         'png',                    180,  180,  'apple-touch-icon'],
            ['web-app-manifest-192x192.png', 'png',                    192,  192,  null],
            ['web-app-manifest-512x512.png', 'png',                    512,  512,  null],
            ['favicon.ico',                  $can_ico ? 'ico' : 'png', null, null, 'shortcut icon'],
        ];

        $files = [];
        foreach ($variants as [$name, $ext, $w, $h]) {
            $cfg = ['extension' => $ext];
            if ($w !== null) { $cfg['width'] = $w; $cfg['height'] = $h; }
            $files[$name] = $source->convert($cfg);
        }

        Rule::object([
            'name' => ['string', Rule::default($_SERVER['HTTP_HOST'] ?? '')],
            'short_name' => 'string',
            'theme_color' => 'hex_color|default:#fff',
            'background_color' => 'hex_color|default:#fff',
            'display'          => 'string|default:standalone',
        ])->after(function (&$data) {
            if (empty($data['short_name']))
                $data['short_name'] = $data['name'];
        })->apply($params);

        $params['icons'] = [
            ['src' => $files['web-app-manifest-192x192.png']->getRelativePath(), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
            ['src' => $files['web-app-manifest-512x512.png']->getRelativePath(), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ];

        $files['site.webmanifest'] = File::make($cache->make('', ['file' => 'site.webmanifest'])->file)->setMime('application/json');
        $files['site.webmanifest']->putContents($params);

        $html = [];

        foreach ($variants as [$name, $m, $w, $h, $rel]) {
            if ($rel === null) continue;
            $html[] = '<link rel="'.$rel.'" type="'.$files[$name]->getMime().'"'
                .($w !== null ? ' sizes="'.$w.'x'.$h.'"' : '')
                .' href="'.$files[$name]->getRelativePath().'">';
        }

        $html[] = '<link rel="manifest" href="'.$files['site.webmanifest']->getRelativePath().'">';

        if (!empty($params['theme_color']))
            $html[] = '<meta name="theme-color" content="'.htmlspecialchars($params['theme_color'], ENT_QUOTES).'">';

        return implode("\n", $html);
    }

    private static function addCss($href, array $attrs = [], string $buffer = ''): void {
        self::push('css', $href, $attrs, $buffer);
    }

    private static function addJs($src, array $attrs = [], string $buffer = ''): void {
        self::push('js', $src, $attrs, $buffer);
    }

    private static function addFont($src, array $attrs = [], string $buffer = ''): void {
        self::push('fonts', $src, $attrs, $buffer);
    }

    private static function svg(string $path, array $attrs = [], bool $return_path = false): string {
        if (pathinfo($path, PATHINFO_EXTENSION) === '') $path .= '.svg';

        $file = File::make($path);
        return $return_path ? $file->getRelativePath() : $file->extract($attrs);
    }

    private static function sprite(string $path, string $icon_id, array $attrs = []): string {
        if ($path === '')
            throw new \InvalidArgumentException('Assets::sprite() requires a sprite file path.');

        if (pathinfo($path, PATHINFO_EXTENSION) === '') $path .= '.svg';

        return File::make($path)->bySprite($icon_id, $attrs);
    }

    private static function addResource($path, array $attrs = [], string $buffer = ''): void {
        $buffer = $buffer !== '' ? $buffer : self::currentBuffer();
        self::ensureBuffer($buffer);

        foreach (self::resolve($path) as $file) {
            $mime = $file->getMime();
            $type = strpos($mime, 'font/') === 0   ? 'fonts'
                  : ($mime === 'text/css'           ? 'css'
                  : ($mime === 'application/javascript' ? 'js'
                  : null));

            if ($type !== null) {
                $key = md5($file->getPathname().'|'.serialize($attrs));
                if (isset(self::$buffers[$buffer]['seen'][$key])) continue;
                self::$buffers[$buffer]['seen'][$key] = true;
                self::$buffers[$buffer]['assets'][$type][] = ['file' => $file, 'attrs' => $attrs];
                continue;
            }

            if ($mime === 'image/svg+xml') {
                self::storeAsset($file->extract($attrs), $buffer);
                continue;
            }

            throw new \InvalidArgumentException("Unsupported asset mime: {$mime} ({$file->getPathname()})");
        }
    }
}
