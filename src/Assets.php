<?php

namespace ST_system;

use ST_system\Traits\HasConfig;
use ST_system\Storage\File;
use ST_system\Rule;
use ST_system\Main;
use ST_system\Storage\Mimes\ImageMime;
use ST_system\Cache\CacheManager;

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
        static $salt = [];
        $salt[$name] ??= Main::hash([__CLASS__, $name]);
        return '<!-- __ASSETS__:'.$salt[$name].':'.$name.'__ -->';
    }

    private static array $buffers = [];
    private static array $stack   = [];
    private static bool $rendering = false;
    private static array $positions = [];
    private static array $recording = [];
    private static bool $view_events_registered = false;
    private File $file;
    private string $buffer;

    public static function create(...$args): self { return new static(...$args); }

    public function __construct(string $path, string $buffer = '') {
        $this->file = File::make($path);
        $this->buffer = $buffer ?: static::config('default_buffer');
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
        switch ($name) {
            case 'mount':
            case 'render':
                return static::$name($args[0] ?? $this->buffer);

            case 'finalize':
                return static::finalize(...$args);

            case 'svg':
                $args[0] = ($args[0] ?? '') !== ''
                    ? $this->file->make($args[0])->getPathname()
                    : $this->file->getPathname();
                return static::svg(...$args);

            case 'sprite':
                $args[0] = $args[0] ?? '';
                $path = ($args[2] ?? '') !== ''
                    ? $this->file->make($args[2])->getPathname()
                    : $this->file->getPathname();
                return static::sprite($path, $args[0], $args[1] ?? []);

            case 'addCss':
            case 'addJs':
            case 'addFont':
            case 'addResource':
                $args[0] = $this->file->find($args[0] ?? '', ['fallback' => 'make']);
                if (!isset($args[1]) || !is_array($args[1])) $args[1] = [];
                if (empty($args[2])) $args[2] = $this->buffer;
                return static::$name(...$args);

            case 'addString':
                if (!isset($args[1]) || !is_string($args[1]) || $args[1] === '') $args[1] = $this->buffer;
                return static::addString(...$args);

            case 'setManifest':
                if (isset($args[0]['favicon']) && is_string($args[0]['favicon']) && $args[0]['favicon'] !== '') {
                    $found = $this->file->find($args[0]['favicon'], ['fallback' => 'make']);
                    $first = is_array($found) ? reset($found) : $found;
                    if ($first instanceof File) $args[0]['favicon'] = $first->getPathname();
                }
                if (empty($args[1])) $args[1] = $this->buffer;
                return static::setManifest(...$args);
        }

        throw new \BadMethodCallException("Method ".__CLASS__."::{$name}() not found");
    }

    private static function currentBuffer(): string {
        return end(self::$stack) ?: static::config('default_buffer');
    }

    public static function registerViewEvents(): void {
        if (self::$view_events_registered) return;
        self::$view_events_registered = true;

        View::on('render_open',     static function () { self::$rendering = true; });
        View::on('render_finalize', static function (string &$html) { $html = self::finalize($html); });
        View::on('render_close',    static function () { self::$rendering = false; });

        View::on('cache_open',   static function () { self::openFrame(); });
        View::on('cache_close',  static function (array &$deps, array &$payload) { self::closeFrame($deps, $payload); });
        View::on('cache_replay', static function (array $payload) { self::replayFrom($payload); });

        View::on('slot_reserve', static function (array &$slots) { $slots['assets'] = self::reserveSlot(); });
        View::on('slot_enter',   static function (array $slots) { self::enterSlot($slots['assets'] ?? null); });
        View::on('slot_leave',   static function () { self::leaveSlot(); });

        View::on('compose_collect', static function (array &$contrib) {
            if (isset($contrib['assets']) && is_array($contrib['assets']))
                $contrib['assets'] = self::rebase($contrib['assets']);
        });
    }

    private static function openFrame(): void {
        self::$recording[] = ['ops' => [], 'depth' => count(self::currentPath())];
    }

    private static function closeFrame(array &$deps, array &$payload): void {
        $frame = self::$recording ? array_pop(self::$recording) : ['ops' => []];
        $payload['assets'] = $frame['ops'];
    }

    private static function replayFrom(array $payload): void {
        $ops = $payload['assets'] ?? null;
        if (!is_array($ops) || !$ops) return;

        self::replay(self::rebase($ops));
    }

    private static function rebase(array $ops): array {
        $base = self::currentPath();
        foreach ($ops as &$op)
            $op['pos'] = array_merge($base, (array)($op['pos'] ?? []));
        unset($op);

        return $ops;
    }

    private static function reserveSlot(): array {
        return array_slice(self::nextPos(), self::recordingDepth());
    }

    private static function enterSlot($slot): void {
        $path = $slot === null
            ? self::nextPos()
            : array_merge(self::currentPath(), (array) $slot);

        self::$positions[] = ['path' => $path, 'n' => 0];
    }

    private static function leaveSlot(): void {
        if (self::$positions) array_pop(self::$positions);
    }

    private static function immediate(): bool {
        return self::$rendering || !static::config('bufferization');
    }

    private static function currentPath(): array {
        if (!self::$positions) self::$positions[] = ['path' => [], 'n' => 0];

        return self::$positions[array_key_last(self::$positions)]['path'];
    }

    private static function nextPos(): array {
        if (!self::$positions) self::$positions[] = ['path' => [], 'n' => 0];

        $i      = array_key_last(self::$positions);
        $path   = self::$positions[$i]['path'];
        $path[] = self::$positions[$i]['n']++;

        return $path;
    }

    private static function recordingDepth(): int {
        return self::$recording
            ? self::$recording[array_key_last(self::$recording)]['depth']
            : 0;
    }

    private static function comparePos(array $a, array $b): int {
        $n = min(count($a), count($b));

        for ($i = 0; $i < $n; $i++)
            if ($a[$i] !== $b[$i]) return $a[$i] <=> $b[$i];

        return count($a) <=> count($b);
    }

    private static function recordOp(array $op, array $pos): void {
        if (!self::$recording) return;

        $i        = array_key_last(self::$recording);
        $op['pos'] = array_slice($pos, self::$recording[$i]['depth']);

        self::$recording[$i]['ops'][] = $op;
    }

    private static function replay(array $ops): void {
        foreach ($ops as $op) {
            $buffer = (string)($op['buffer'] ?? static::config('default_buffer'));
            $kind   = (string)($op['op'] ?? '');
            $pos    = (array)($op['pos'] ?? []);

            if ($kind === 'content') {
                self::storeAsset((string)($op['html'] ?? ''), $buffer, $pos);
                continue;
            }

            if ($kind === 'asset') {
                self::ensureBuffer($buffer);
                $file  = File::make((string)($op['path'] ?? ''));
                $attrs = (array)($op['attrs'] ?? []);
                $key   = Main::hash([$file->getPathname(), $attrs]);
                if (isset(self::$buffers[$buffer]['seen_assets'][$key])) continue;
                self::$buffers[$buffer]['seen_assets'][$key] = true;
                self::$buffers[$buffer]['assets'][(string)($op['type'] ?? '')][] = ['file' => $file, 'attrs' => $attrs, 'pos' => $pos];
            }
        }
    }

    private static function ensureBuffer(string $name): void {
        if (!isset(self::$buffers[$name]))
            self::$buffers[$name] = [
                'content'      => [],
                'started'      => false,
                'seen_assets'  => [],
                'seen_strings' => [],
                'assets'       => ['css' => [], 'js' => [], 'fonts' => []],
            ];
    }

    private static function contentHtml(string $name): string {
        self::ensureBuffer($name);

        $items = self::$buffers[$name]['content'];
        usort($items, fn($a, $b) => self::comparePos($a['pos'], $b['pos']));

        return implode('', array_column($items, 'html'));
    }

    private static function unpackBuffer(string $name): string {
        $content = self::contentHtml($name);
        self::$buffers[$name]['content'] = [];
        return $content;
    }

    private static function storeAsset(string $html, string $buffer, ?array $pos = null): void {
        self::ensureBuffer($buffer);

        $key = md5($html);
        if (isset(self::$buffers[$buffer]['seen_strings'][$key])) return;

        $live = $pos === null;
        $pos ??= self::nextPos();

        self::$buffers[$buffer]['seen_strings'][$key] = true;
        self::$buffers[$buffer]['content'][] = ['pos' => $pos, 'html' => $html];

        if ($live) self::recordOp(['op' => 'content', 'html' => $html, 'buffer' => $buffer], $pos);
    }

    private static function mount(string $name): void {
        static $is_shutdown_initialized = false;
        if (!$is_shutdown_initialized) {
            $is_shutdown_initialized = true;
            register_shutdown_function(static function () {
                if (!static::config('bufferization')) { self::$stack = []; return; }
                while ($name = array_pop(self::$stack)) {
                    $captured = ob_get_clean();
                    if ($captured === false) continue;
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

        if (!self::immediate()) {
            self::$stack[] = $name;
            echo self::unpackBuffer($name);
            ob_start();
            echo self::placeholder($name);
        } else {
            echo self::placeholder($name);
        }
    }

    private static function render(string $buffer): void {
        if (self::immediate()) {
            if (isset(self::$buffers[$buffer]))
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

            usort($items, fn($a, $b) => self::comparePos($a['pos'] ?? [], $b['pos'] ?? []));

            $groups = [];
            foreach ($items as $item)
                $groups[Main::hash($item['attrs'])][] = $item;

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

        $found = File::find($src, ['fallback' => 'make']);

        foreach ($found as $file) {
            $key = Main::hash([$file->getPathname(), $attrs]);
            if (isset(self::$buffers[$buffer]['seen_assets'][$key])) continue;

            $pos = self::nextPos();

            self::$buffers[$buffer]['seen_assets'][$key] = true;
            self::$buffers[$buffer]['assets'][$type][] = ['file' => $file, 'attrs' => $attrs, 'pos' => $pos];
            self::recordOp(['op' => 'asset', 'type' => $type, 'path' => $file->getPathname(), 'attrs' => $attrs, 'buffer' => $buffer], $pos);
        }
    }

    private static function finalize(string $html): string {
        foreach (array_keys(self::$buffers) as $name) {
            $replacement = self::contentHtml($name) . self::buildAssetsHtml($name);
            $html = str_replace(self::placeholder($name), $replacement, $html);

            self::$buffers[$name]['started'] = false;
            self::$buffers[$name]['content'] = [];
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

        $cache = CacheManager::make([__CLASS__, 'manifest', $params, $source->getPathname(), $source->mtime], [
            'driver' => 'filesystem',
            'dir'    => Main::glue([CacheManager::config('default.dir'), Main::basename(static::class)], '/'),
            'ttl'    => -1,
        ]);

        $html = $cache->remember(fn() => self::generateManifest($source, $params, $cache), -1);

        self::storeAsset($html, $buffer !== '' ? $buffer : self::currentBuffer());

        $done = true;
    }

    private static function generateManifest(File $source, array $params, CacheManager $cache): string {
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

        $found = File::find($path, ['fallback' => 'make']);

        foreach ($found as $file) {
            $mime = $file->getMime();
            $type = strpos($mime, 'font/') === 0   ? 'fonts'
                  : ($mime === 'text/css'           ? 'css'
                  : ($mime === 'application/javascript' ? 'js'
                  : null));

            if ($type !== null) {
                $key = Main::hash([$file->getPathname(), $attrs]);
                if (isset(self::$buffers[$buffer]['seen_assets'][$key])) continue;

                $pos = self::nextPos();

                self::$buffers[$buffer]['seen_assets'][$key] = true;
                self::$buffers[$buffer]['assets'][$type][] = ['file' => $file, 'attrs' => $attrs, 'pos' => $pos];
                self::recordOp(['op' => 'asset', 'type' => $type, 'path' => $file->getPathname(), 'attrs' => $attrs, 'buffer' => $buffer], $pos);
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
