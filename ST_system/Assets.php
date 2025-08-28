<?php

namespace ST_system;

class Assets {

    private const config = [
        'default_buffer' => 'head',
        'bufferization' => true,
        'fonts' => [
            'weights_map' => [
                'thin' => 100,
                'extralight' => 200,
                'light' => 300,
                'regular' => 400,
                'medium' => 500,
                'semibold' => 600,
                'extrabold' => 800,
                'bold' => 700,
                'black' => 900,
            ],
            'styles_map' => [
                'italic' => 'italic',
                'oblique' => 'oblique'
            ],
        ],
        'extensions_map' => [
            'woff2' => 'woff2',
            'woff'  => 'woff',
            'ttf'   => 'truetype',
            'otf'   => 'opentype',
            'eot'   => 'embedded-opentype',
            'svg'   => 'svg',
            'css'   => 'css',
            'js'    => 'js'
        ],
    ];

    private static $buffers = [];
    private static $stack = [];
    private static $instance;

    private static function current_buffer() {
        return end(self::$stack) ?: self::config['default_buffer'];
    }

    private static function ensure_buffer(string $name) {
        if (!isset(self::$buffers[$name])) {
            self::$buffers[$name] = [
                'content' => '',
                'started' => false,
                'seen' => [],
            ];
        }
    }

    private static function unpack_buffer(string $name) {
        self::ensure_buffer($name);
        $content = self::$buffers[$name]['content'];

        self::$buffers[$name]['content'] = '';
        return $content;
    }

    final public static function mount(string $name) {            
        self::ensure_buffer($name);

        if (self::$buffers[$name]['started'])
            throw new \RuntimeException("Buffer '{$name}' already started.");

        self::$buffers[$name]['started'] = true;
        array_push(self::$stack, $name);
    
        if (self::config['bufferization']) {
            echo self::unpack_buffer($name);

            ob_start();
        } else {
            echo '<!-- '.__CLASS__.'::mount("'.$name.'") -->';
        }
    }

    final public static function render_html(string $html) {
        if (self::config['bufferization'])
            return;

        foreach (self::$buffers as $name => ['content' => &$content, 'started' => &$started]) {
            $html = str_replace('<!-- '.__CLASS__.'::mount("'.$name.'") -->', $content, $html);
            $started = false;
            $content = '';
        }

        return $html;
    }

    final public static function render(string $buffer) {

        if (!self::config['bufferization']) {
            array_pop(self::$stack);
            return;
        }
            
        if (!in_array($buffer, self::$stack)) {

            if (isset(self::$buffers[$buffer]))
                echo self::unpack_buffer($buffer);
            
            return;
        }

        if ($buffer != self::current_buffer())
            throw new \RuntimeException("Buffer mismatch: trying to end '{$buffer}', but top active is '".self::current_buffer()."'. Use LIFO order.");
        
        self::$buffers[$buffer]['content'] .= ob_get_clean();

        array_pop(self::$stack);
    
        self::$buffers[$buffer]['started'] = false;
        
        echo self::unpack_buffer($buffer);
    }

    private static function store_asset(string $html, string $buffer) {
        self::ensure_buffer($buffer);

        $key = md5($html);
        if (isset(self::$buffers[$buffer]['seen'][$key]))
            return;
        
        self::$buffers[$buffer]['seen'][$key] = true;

        self::$buffers[$buffer]['content'] = self::$buffers[$buffer]['content'].$html;
    }

    private string $path = '';
    private string $dir = '';
    private bool $is_uri;
    private string $buffer;

    final public static function full_path(string $path) {      
        
        if (strpos($path, '~') === 0)
            $path = $_SERVER['DOCUMENT_ROOT'].DIRECTORY_SEPARATOR.trim($path, DIRECTORY_SEPARATOR.'~');
        elseif (strpos($path, DIRECTORY_SEPARATOR) !== 0)
            $path = __DIR__.DIRECTORY_SEPARATOR.trim($path, DIRECTORY_SEPARATOR);
        
        return realpath($path);
    }

    final public static function is_uri(string $path_or_uri) {
        return filter_var($path_or_uri, FILTER_VALIDATE_URL);
    }

    final public function __construct(string $path_or_uri, string $buffer = '') {

        if (self::is_uri($path_or_uri)) {
            $this->path = $path_or_uri;
            $this->is_uri = true;
        } else {
            $realpath = self::full_path($path_or_uri);

            if (is_dir($realpath))
                $this->dir = $realpath;
            else {
                $this->path = $realpath;
                $this->dir = dirname($realpath);
            }

            $this->is_uri = false;
        }
        
        $this->buffer = $buffer != '' ? $buffer : self::current_buffer();
    }

    final public function __call($name, $arguments) {
        self::$instance = $this;
        
        $arguments = array_pad($arguments, 1, null);

        switch ($name) {
            case 'path':
            case 'dir': 
                return $this->{$name};
            case 'resources':
                return self::{$name}($this->dir().DIRECTORY_SEPARATOR.trim(array_shift($arguments), DIRECTORY_SEPARATOR), ...$arguments);
        }
    }

    final public static function __callStatic($name, $arguments) {
        self::$instance = null;

        $arguments = array_pad($arguments, 3, null);

        switch ($name) {
            case 'resources':
                if (!is_array($arguments[0]))
                    $arguments[0] = [$arguments[0]];

                $files = [];
                foreach ($arguments[0] as $input)
                    $files = array_merge($files, self::resources($input));

                return $files;
        }
    }

    final public static function __callStatic2($name, $arguments) {
        self::$instance = null;

        $arguments = array_pad($arguments, 3, null);
        switch ($name) {
            case 'add_string':                
                if (!is_array($arguments[0]))
                    $arguments[0] = [$arguments[0]];
                break;
            case 'add_css':
            case 'add_js':
            case 'add_font':
            case 'resources':
                if (!is_array($arguments[0]))
                    $arguments[0] = [$arguments[0]];

                $files = [];
                foreach ($arguments[0] as $string)
                    $files = array_merge($files, self::_resources($string));

                break;
        }

        switch ($name) {
            case 'resources':
                return $files;
            case 'sprite':
                return call_user_func([self::class, "_{$name}"], $arguments[0], $arguments[1], $arguments[2]);
            case 'svg':
                return call_user_func([self::class, "_{$name}"], $arguments[0], $arguments[1] ?? [], $arguments[2] ?? false);
            case 'add_css':
            case 'add_js':
            case 'add_font':
                foreach ($files as $file)
                    call_user_func([self::class, "_{$name}"], $file, $arguments[1] ?? [], $arguments[2] ?? self::current_buffer());
    
                return self::$instance;
            case 'add_string':
                foreach ($arguments[0] as $string)             
                    call_user_func([self::class, "_{$name}"], $string, $arguments[1] ?? self::current_buffer());
                
                return self::$instance;
                
            default: throw new \Error("Call to undefined static method " . __CLASS__ . "::{$name}()");
        }
    }

    final public function __call2($name, $arguments) {
        self::$instance = $this;

        $arguments = array_pad($arguments, 3, null);
        switch ($name) {
            case 'add_string':                
                if (!is_array($arguments[0]))
                    $arguments[0] = [$arguments[0]];
                break;
            case 'add_css':
            case 'add_js':
            case 'add_font':
            case 'resources':
                if (!is_array($arguments[0]))
                    $arguments[0] = [$arguments[0]];

                $files = [];
                foreach ($arguments[0] as $string)
                    $files = array_merge($files, self::_resources(self::$instance->path.DIRECTORY_SEPARATOR.ltrim($string, DIRECTORY_SEPARATOR)));

                break;
        }

        switch ($name) {
            case 'resources':
                return $files;
            case 'sprite':
                return call_user_func([self::class, "_{$name}"], $arguments[0], $arguments[1] ?? [], $arguments[2] != '' ? self::$instance->path.'/'.ltrim($arguments[2], '/') : self::$instance->path);
            case 'svg':
                if (self::$instance->is_uri)
                    throw new \Exception("SVG можно взять лишь у локального ресурса.");
                return call_user_func([self::class, "_{$name}"], self::$instance->path.'/'.ltrim($arguments[0], '/'), $arguments[1] ?? [], $arguments[2] ?? false);
            case 'add_css':
            case 'add_js':
            case 'add_font':
                foreach ($files as $file)
                    call_user_func([self::class, "_{$name}"], $file, $arguments[1] ?? [], self::$instance->buffer ?? $arguments[2] ?? self::current_buffer());
    
                return self::$instance;
            case 'add_string':
                foreach ($arguments[0] as $string)             
                    call_user_func([self::class, "_{$name}"], $string, self::$instance->buffer ?? $arguments[1] ?? self::current_buffer());
                
                return self::$instance;
                
            default: throw new \Error("Call to undefined method " . __CLASS__ . "::{$name}()");
        }
    }

    private static function resources(string $input, array $PARAMS = []) {

        $max_files = isset($PARAMS['max_files']) ? (int)$PARAMS['max_files'] : 50;
        $follow_symbolic_links = isset($PARAMS['follow_symbolic_links']) ? (bool)$PARAMS['follow_symbolic_links'] : false;
        $exclude_hidden_files = isset($PARAMS['exclude_hidden_files']) ? (bool)$PARAMS['exclude_hidden_files'] : true;
        $return_relative_path = isset($PARAMS['return_relative_path']) ? (bool)$PARAMS['return_relative_path'] : true;
        
        if ((self::$instance && self::$instance->is_uri) || (!self::$instance && self::is_uri($input)))
            return [$input];
        
        $input = self::full_path($input);

        if (!preg_match('/[\\(\\[\\{\\|\\?\\+\\*]/', $input)) {
            if (file_exists($input)) {
                if (is_dir($input)) {
                    $files = [];
                    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($input)) as $file)
                        if ($file->isFile() && in_array(pathinfo($file->getFilename(), PATHINFO_EXTENSION), array_keys(self::config['extensions_map'])))
                            $files[] = $return_relative_path ? substr($file->getPathname(), strlen($_SERVER['DOCUMENT_ROOT'])) : $file->getPathname();
                    return $files;
                } else
                    return [$return_relative_path ? substr($input, strlen($_SERVER['DOCUMENT_ROOT'])) : $input];
            }

            return [];
        }

        $dir_prefix = substr($input, 0, preg_match('/[\\(\\[\\{\\|\\?\\+\\*]/', $input, $m, PREG_OFFSET_CAPTURE)
            ? $m[0][1]
            : 0
        );

        $start_dir = $dir_prefix == ''
            ? self::full_path($input)
            : (is_dir($dir_prefix)
                ? rtrim($dir_prefix, '/')
                : dirname($dir_prefix)
        );

        if (!is_dir($start_dir) || !is_readable($start_dir))
            throw new \InvalidArgumentException("Start directory '{$start_dir}' does not exist or is not readable.");
        
        $pattern = str_replace('\\', '/', $input);

        $delimiter = '#';
        foreach (['#', '~', '%', '@', '!', '$', '`', ';', '|'] as $candidate)
            if (strpos($pattern, $candidate) === false) {
                $delimiter = $candidate;
                break;
            }
        
        $pattern = $delimiter.'^'.$pattern.'$'.$delimiter.'u';

        $results = [];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($start_dir, \FilesystemIterator::SKIP_DOTS | (!$follow_symbolic_links ? \FilesystemIterator::CURRENT_AS_FILEINFO : 0)),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileinfo) {

            if ($exclude_hidden_files) {
                $parts = explode('/', str_replace('\\', '/', $iterator->getSubPathname()));
                $skip = false;
                foreach ($parts as $p)
                    if ($p !== '' && str_starts_with($p, '.')) {
                        $skip = true;
                        break;
                    }
                
                if ($skip)
                    continue;
            }

            if ($fileinfo->isFile()) {
                if (@preg_match($pattern, $fileinfo->getPathname()) === 1) {
                    $results[] = $return_relative_path ? substr($fileinfo->getRealPath(), strlen($_SERVER['DOCUMENT_ROOT'])) : $fileinfo->getRealPath();
                    if ($max_files > 0 && count($results) >= $max_files)
                        break;
                }
            }
        }

        return $results;
    }

    private static function _add_css(string $href, array $attributes = [], string $buffer = '') {
        self::ensure_buffer($buffer);

        $attributes['rel']  = 'stylesheet';
        $attributes['href'] = $href;

        self::store_asset("<link ".implode(' ', array_map(fn($k,$v) => $k.'="'.htmlspecialchars($v, ENT_QUOTES|ENT_SUBSTITUTE).'"', array_keys($attributes), $attributes)).">\n", $buffer);
    }

    private static function _add_js(string $src, array $attributes = [], string $buffer = '') {
        self::ensure_buffer($buffer);

        $attributes['type'] = $attributes['type'] ?? 'text/javascript';
        $attributes['src']  = $src;
        
        self::store_asset("<script ".implode(' ', array_map(fn($k,$v) => $k.'="'.htmlspecialchars($v, ENT_QUOTES|ENT_SUBSTITUTE).'"', array_keys($attributes), $attributes))."></script>\n", $buffer);
    }

    private static function _add_string(string $string, string $buffer = '') {
        self::ensure_buffer($buffer);

        self::store_asset($string."\n", $buffer);
    }

    private static function _add_font(string $src, array $attributes = [], string $buffer = '') {
        self::ensure_buffer($buffer);

        $is_uri = self::is_uri($src);

        $file_name = $is_uri
            ? pathinfo(parse_url($src, PHP_URL_PATH), PATHINFO_FILENAME)
            : pathinfo($src, PATHINFO_FILENAME);
        $file_extension = pathinfo($src, PATHINFO_EXTENSION);

        if (!isset($PARAMS['font-weight'])) {
            $weight = self::config['fonts']['weights_map']['regular'];
            foreach (self::config['fonts']['weights_map'] as $key => $w)
                if (stripos($file_name, $key) !== false) {
                    $weight = $w; 
                    break; 
                }
        } else $weight = $PARAMS['font-weight'];

        if (!isset($PARAMS['font-style'])) {
            $style = 'normal';
            foreach (self::config['fonts']['styles_map'] as $key => $s)
                if (stripos($file_name, $key) !== false) {
                    $style = $s; 
                    break; 
                }
        } else $style = $PARAMS['font-style'];

        $format = isset($PARAMS['format']) ? $PARAMS['format'] : (isset(self::config['extensions_map'][$file_extension]) ? self::config['extensions_map'][$file_extension] : $file_extension);
        $font_family = isset($PARAMS['font-family']) ? $PARAMS['font-family'] : trim(preg_replace( '/(?<!^)(?=[A-Z])/', ' ', preg_match('/^[A-Za-z]+/', $file_name, $matches) ? $matches[0] : $file_name));
        $src = isset($PARAMS['src']) ? $PARAMS['src'] : $src;
        $display = isset($PARAMS['font-display']) ? $PARAMS['font-display'] : 'swap';

        self::store_asset(<<<HTML
            <style>
                @font-face {
                        font-family: '{$font_family}';
                        src: url('{$src}') format('{$format}');
                        font-weight: {$weight};
                        font-style: {$style};
                        font-display: {$display};
                    }
            </style>
            HTML, 
            $buffer
        );
    }

    private static function _svg(string $path, array $attributes = [], bool $return_path = false) {
        static $counter = 0;

        if (pathinfo($path, PATHINFO_EXTENSION) === '')
            $path .= '.svg';

        if (!file_exists($path))
            throw new \Exception("SVG file not found: " . $path);

        if ($return_path)
            return substr($path, strlen($_SERVER['DOCUMENT_ROOT']));
        
        $svg = @file_get_contents($path);
        if ($svg === false)
            throw new \Exception("Failed to read SVG file: " . $path);
        
        $counter++;
        $suffix = '_'.$counter;

        if (class_exists('DOMDocument')) {
            $dom = new \DOMDocument();

            libxml_use_internal_errors(true);

            $dom->loadXML($svg, LIBXML_NOWARNING | LIBXML_NOERROR);

            $xpath = new \DOMXPath($dom);
            foreach ($xpath->query('//*[@id]') as $el) {
                $oldId = $el->getAttribute('id');
                $newId = $oldId . $suffix;
                $el->setAttribute('id', $newId);

                foreach ($xpath->query('//*[@*]') as $refEl)
                    foreach ($refEl->attributes as $attr) {
                        $refEl->setAttribute(
                            $attr->name,
                            preg_replace('/url\(#' . preg_quote($oldId, '/') . '\)/', 'url(#' . $newId . ')', $attr->value)
                        );
                    }
            }

            if (!empty($attributes) && $dom->documentElement)
                foreach ($attributes as $k => $v)
                    $dom->documentElement->setAttribute($k, $v);
        
            return $dom->saveXML($dom->documentElement);
        } else {
            $svg = preg_replace(
                '/\bid\s*=\s*(["\']?)([^"\'>\s]+)\1/',
                'id="$2' . $suffix . '"',
                $svg
            );

            $svg = preg_replace(
                '/url\(#([^)]+)\)/',
                'url(#$1' . $suffix . ')',
                $svg
            );

            if (!empty($attributes) && preg_match('/<svg\b([^>]*)>/i', $svg, $matches)) {
                $existingAttrs = $matches[1];

                foreach ($attributes as $k => $v) {
                    $attrPattern = '/\b' . preg_quote($k, '/') . '\s*=\s*["\'][^"\']*["\']/';
                    $attrValue   = sprintf('%s="%s"', $k, htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE));

                    if (preg_match($attrPattern, $existingAttrs))
                        $existingAttrs = preg_replace($attrPattern, $attrValue, $existingAttrs);
                    else
                        $existingAttrs .= ' ' . $attrValue;
                }

                $existingAttrs = ltrim($existingAttrs) ? ' ' . ltrim($existingAttrs) : '';

                $svg = preg_replace('/<svg\b[^>]*>/i', '<svg' . $existingAttrs . '>', $svg);
            }

            return $svg;
        }
    }

    private static function _sprite(string $icon_id, array $attributes = [], string $path) {
        if (!file_exists($path))
            throw new \Exception("SVG file not found: " . $path);

        $attr_str = '';
        foreach ($attributes as $k => $v)
            $attr_str .= sprintf(' %s="%s"', $k, $v);
        
        return sprintf('<svg %s><use xlink:href="%s"></use></svg>', $attr_str, rtrim(substr($path, strlen($_SERVER['DOCUMENT_ROOT'])), '/').'#'.$icon_id);
    }

}

/**
 * File - расширение SplFileInfo с поддержкой кеширования URI->локальных файлов.
 *
 * Особенности:
 * - Поддержка относительных путей и ~/... в конструкторе для локальных файлов.
 * - Для URI: кеширование в локальной папке (sha256(url) -> файл + .meta.json).
 * - Поддержка Cache-Control / Expires -> TTL.
 * - Условная валидация через ETag / Last-Modified (If-None-Match / If-Modified-Since).
 * - Atomic сохранение (tmp + rename) и flock по .meta.json.lock.
 * - Опция force_cache_until и force_cache_map: принудительно держать файл в кеше до даты.
 * - Параллельная загрузка через curl_multi (fetchManyToCache).
 *
 * Требования: PHP 8+, расширение cURL.
 */

class File extends \SplFileInfo
{
    private bool $isUri;
    private string $original;
    private string $cacheDir;

    // Статическая конфигурация (приватная)
    private static array $config = [
        'cache_dir' => null,
        'connect_timeout' => 10,
        'timeout' => 300,
        'max_retries' => 3,
        'retry_backoff_base' => 1.5,
        'default_ttl' => 3600,
        'parallel_limit' => 6,
        'compute_checksum' => false,
        'force_cache_until' => null, // timestamp or null
        'force_cache_map' => [],     // pattern => timestamp|null
    ];

    /** ----------------------- Конфигурация ----------------------- */

    public static function setConfig(array $cfg): void
    {
        self::$config = array_merge(self::$config, $cfg);
    }

    /** Устанавливает глобальную дату (timestamp | date string | null) */
    public static function setForceCacheUntil(null|int|string|\DateTimeInterface $until): void
    {
        if ($until === null) {
            self::$config['force_cache_until'] = null;
            return;
        }
        if ($until instanceof \DateTimeInterface) {
            self::$config['force_cache_until'] = $until->getTimestamp();
            return;
        }
        if (is_numeric($until)) {
            self::$config['force_cache_until'] = (int)$until;
            return;
        }
        $ts = strtotime((string)$until);
        self::$config['force_cache_until'] = $ts === false ? null : $ts;
    }

    /**
     * Устанавливает карту правил: pattern => (timestamp|string|\DateTimeInterface|null)
     * pattern может быть:
     *  - 'prefix:https://cdn.example/'  (префикс)
     *  - 'regex:#^https://images\..*#' (регексп)
     *  - точная строка URL
     *  - просто подстрока (fallback)
     */
    public static function setForceCacheMap(array $map): void
    {
        $out = [];
        foreach ($map as $k => $v) {
            if ($v === null) { $out[$k] = null; continue; }
            if ($v instanceof \DateTimeInterface) $out[$k] = $v->getTimestamp();
            elseif (is_numeric($v)) $out[$k] = (int)$v;
            else $out[$k] = ($p = strtotime((string)$v)) === false ? null : $p;
        }
        self::$config['force_cache_map'] = $out;
    }

    /** ----------------------- Конструктор ----------------------- */

    public function __construct(string $file_name, ?string $cacheDir = null)
    {
        $this->original = $file_name;
        $this->isUri = (bool) filter_var($file_name, FILTER_VALIDATE_URL);
        $this->cacheDir = $cacheDir ?? (self::$config['cache_dir'] ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'file_uri_cache'));

        if (!$this->isUri) {
            // Локальные пути: ~ -> DOCUMENT_ROOT|HOME, относительно -> __DIR__
            if (strpos($file_name, '~') === 0) {
                $file_name = ($_SERVER['DOCUMENT_ROOT'] ?? rtrim(getenv('HOME') ?: '', '/'))
                    . DIRECTORY_SEPARATOR . trim($file_name, DIRECTORY_SEPARATOR . '~');
            } elseif (strpos($file_name, DIRECTORY_SEPARATOR) !== 0) {
                $file_name = __DIR__ . DIRECTORY_SEPARATOR . trim($file_name, DIRECTORY_SEPARATOR);
            }
            parent::__construct($file_name);
        }
    }

    public function isUri(): bool { return $this->isUri; }

    /** ----------------------- Helpers: ключи/пути ----------------------- */

    protected function normalizeUrl(string $url): string
    { //Тут надо убирать http, rtrim /, оставить домен + uri
        return trim($url);
    }

    protected function cacheKey(string $url): string
    {
        return hash('sha256', $this->normalizeUrl($url));
    }

    protected function cachePathForKey(string $key): string
    {
        $p1 = substr($key, 0, 2);
        $p2 = substr($key, 2, 2);
        $dir = rtrim($this->cacheDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $p1 . DIRECTORY_SEPARATOR . $p2;
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        return $dir . DIRECTORY_SEPARATOR . $key;
    }

    protected function metaPath(string $filePath): string
    {
        return $filePath . '.meta.json';
    }

    /** ----------------------- Force-cache rules ----------------------- */

    /**
     * Возвращает timestamp forced_until для данного URL или null.
     */
    protected function getForcedUntilForUrl(string $url): ?int
    {
        foreach (self::$config['force_cache_map'] as $pattern => $ts) {
            if ($ts === null) continue;
            if (str_starts_with($pattern, 'prefix:')) {
                $pref = substr($pattern, 7);
                if (str_starts_with($url, $pref)) return $ts;
            } elseif (str_starts_with($pattern, 'regex:')) {
                $re = substr($pattern, 6);
                if (@preg_match($re, $url)) return $ts;
            } elseif ($pattern === $url) {
                return $ts;
            } else {
                if (strpos($url, $pattern) !== false) return $ts;
            }
        }
        $g = self::$config['force_cache_until'] ?? null;
        return $g !== null ? (int)$g : null;
    }

    /** ----------------------- Meta helpers ----------------------- */

    protected function loadMeta(string $metaPath): ?array
    {
        if (!is_file($metaPath)) return null;
        $json = @file_get_contents($metaPath);
        if ($json === false) return null;
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    protected function saveMeta(string $metaPath, array $meta): void
    {
        file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function parseCacheControl(?string $cc): array
    {
        $res = [];
        if (!$cc) return $res;
        $parts = array_map('trim', explode(',', $cc));
        foreach ($parts as $p) {
            if (strpos($p, '=') !== false) {
                [$k, $v] = explode('=', $p, 2);
                $res[strtolower($k)] = trim($v, '" ');
            } else {
                $res[strtolower($p)] = true;
            }
        }
        return $res;
    }

    protected function computeTtlFromHeaders(array $headers): ?int
    {
        if (!empty($headers['cache-control'])) {
            $cc = $this->parseCacheControl($headers['cache-control']);
            if (isset($cc['max-age']) && is_numeric($cc['max-age'])) return (int)$cc['max-age'];
            if (isset($cc['s-maxage']) && is_numeric($cc['s-maxage'])) return (int)$cc['s-maxage'];
            if (isset($cc['no-cache']) || isset($cc['no-store'])) return 0;
        }
        if (!empty($headers['expires'])) {
            $t = strtotime($headers['expires']);
            if ($t !== false) {
                $now = time();
                $delta = $t - $now;
                return $delta > 0 ? $delta : 0;
            }
        }
        return null;
    }

    protected function isCachedFresh(array $meta): bool
    {
        $now = time();
        if (isset($meta['cached_until'])) return $meta['cached_until'] > $now;
        if (isset($meta['fetched_at'])) return ($meta['fetched_at'] + (self::$config['default_ttl'] ?? 3600)) > $now;
        return false;
    }

    /** ----------------------- Основной метод: fetchToCache ----------------------- */

    /**
     * Скачивает URI в кеш (если нужно) и возвращает путь к локальному файлу.
     *
     * @param bool $force - форсировать перезагрузку
     * @param array $opts - опции:
     *   - compute_checksum => bool
     *   - default_ttl => int (seconds)
     * @return string|null - путь к локальному файлу или null при ошибке
     * @throws RuntimeException
     */
    public function fetchToCache(bool $force = false, array $opts = []): ?string
    {
        if (!$this->isUri()) {
            return $this->getRealPath() ?: $this->getPathname();
        }

        $url = $this->original;
        $key = $this->cacheKey($url);
        $filePath = $this->cachePathForKey($key);
        $metaPath = $this->metaPath($filePath);

        // lock по мета-файлу
        $lockFp = fopen($metaPath . '.lock', 'c');
        if ($lockFp === false) throw new \RuntimeException("Cannot open lock file {$metaPath}.lock");
        flock($lockFp, LOCK_EX);

        $meta = $this->loadMeta($metaPath);

        // forced cache logic: если задан forcedUntil и файл есть и сейчас < forcedUntil -> вернуть локальный файл
        $forcedUntil = $this->getForcedUntilForUrl($url);
        if ($forcedUntil !== null && is_file($filePath)) {
            $meta = $meta ?? [];
            $meta['forced_until'] = $forcedUntil;
            $this->saveMeta($metaPath, $meta);

            if (time() < $forcedUntil) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
                return $filePath;
            }
            // если просрочено - продолжаем обычный flow
        }

        // если файл свеж и не форсим - возвращаем
        if (!$force && is_file($filePath) && $meta && $this->isCachedFresh($meta)) {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
            return $filePath;
        }

        // подготовим условные заголовки
        $condHeaders = [];
        if (!$force && $meta) {
            if (!empty($meta['etag'])) $condHeaders[] = 'If-None-Match: ' . $meta['etag'];
            if (!empty($meta['last_modified'])) $condHeaders[] = 'If-Modified-Since: ' . $meta['last_modified'];
        }

        // сначала HEAD
        $head = $this->httpHead($url, $condHeaders);

        if ($head !== null && ($head['http_code'] ?? 0) === 304) {
            // актуально
            $meta['fetched_at'] = time();
            $ttl = $this->computeTtlFromHeaders($head['headers']) ?? ($opts['default_ttl'] ?? self::$config['default_ttl']);
            $meta['cache_ttl'] = $ttl;
            $meta['cached_until'] = time() + (int)$ttl;
            $meta['forced_until'] = $forcedUntil ?? ($meta['forced_until'] ?? null);
            $this->saveMeta($metaPath, $meta);
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
            return $filePath;
        }

        // GET with retries
        $retries = 0;
        $maxRetries = self::$config['max_retries'] ?? 3;
        $backoffBase = self::$config['retry_backoff_base'] ?? 1.5;
        $lastErr = null;
        while (true) {
            $res = $this->httpGetToFileWithRetry($url, $filePath . '.tmp', $condHeaders, $retries);
            if ($res !== false) {
                // success
                $checksum = null;
                if ($opts['compute_checksum'] ?? (self::$config['compute_checksum'] ?? false)) {
                    $checksum = hash_file('sha256', $filePath . '.tmp');
                }

                // atomically move
                rename($filePath . '.tmp', $filePath);

                $headers = $res['headers'] ?? [];
                $ttlFromHeaders = $this->computeTtlFromHeaders($headers);
                $ttl = $ttlFromHeaders ?? ($opts['default_ttl'] ?? self::$config['default_ttl']);

                $metaNew = [
                    'original_url' => $url,
                    'effective_url' => $res['effective_url'] ?? $url,
                    'http_code' => $res['http_code'] ?? null,
                    'content_length' => $res['content_length'] ?? filesize($filePath),
                    'content_type' => $headers['content-type'] ?? null,
                    'etag' => $headers['etag'] ?? $meta['etag'] ?? null,
                    'last_modified' => $headers['last-modified'] ?? $meta['last_modified'] ?? null,
                    'fetched_at' => time(),
                    'cache_ttl' => $ttl,
                    'cached_until' => time() + (int)$ttl,
                    'checksum' => $checksum,
                    'headers' => $headers,
                    'forced_until' => $forcedUntil ?? ($meta['forced_until'] ?? null),
                ];
                $this->saveMeta($metaPath, $metaNew);

                flock($lockFp, LOCK_UN);
                fclose($lockFp);
                return $filePath;
            }

            $lastErr = "Attempt {$retries} failed";
            $retries++;
            if ($retries > $maxRetries) break;
            $delay = pow($backoffBase, $retries - 1);
            usleep((int)($delay * 1000000));
        }

        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        throw new \RuntimeException("Failed to download {$url} after {$retries} retries. Last: {$lastErr}");
    }

    /** Очистка кеша (для данного URL) */
    public function purgeCache(): bool
    {
        if (!$this->isUri()) return false;
        $url = $this->original;
        $key = $this->cacheKey($url);
        $filePath = $this->cachePathForKey($key);
        $metaPath = $this->metaPath($filePath);

        $lock = fopen($metaPath . '.lock', 'c');
        if ($lock === false) return false;
        flock($lock, LOCK_EX);
        $ok = true;
        if (is_file($filePath)) $ok = $ok && @unlink($filePath);
        if (is_file($metaPath)) $ok = $ok && @unlink($metaPath);
        flock($lock, LOCK_UN); fclose($lock);
        return $ok;
    }

    /** ----------------------- HTTP helpers ----------------------- */

    protected function httpHead(string $url, array $extraHeaders = []): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => self::$config['connect_timeout'],
            CURLOPT_TIMEOUT => min(30, self::$config['timeout']),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $extraHeaders,
        ]);
        $raw = curl_exec($ch);
        $err = curl_errno($ch);
        $info = curl_getinfo($ch);
        if ($err) {
            curl_close($ch);
            return null;
        }
        $headersStr = $raw ?: '';
        [$hdr] = explode("\r\n\r\n", $headersStr . "\r\n\r\n", 2);
        $parsed = $this->parseHeaders($hdr);
        curl_close($ch);
        return [
            'http_code' => $info['http_code'] ?? null,
            'effective_url' => $info['url'] ?? $url,
            'headers' => $parsed,
            'content_length' => $info['download_content_length'] ?? null,
        ];
    }

    /**
     * GET to file (single attempt). Возвращает array or false.
     */
    protected function httpGetToFileWithRetry(string $url, string $outTmp, array $extraHeaders = [], int $attempt = 0)
    {
        $fp = fopen($outTmp, 'w');
        if (!$fp) return false;

        $responseHeaders = [];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => self::$config['connect_timeout'],
            CURLOPT_TIMEOUT => self::$config['timeout'],
            CURLOPT_FILE => $fp,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $extraHeaders,
        ]);

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine) use (&$responseHeaders) {
            $trim = trim($headerLine);
            if ($trim === '') return strlen($headerLine);
            $parts = explode(':', $trim, 2);
            if (count($parts) === 2) $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            else $responseHeaders['status-line'] = $trim;
            return strlen($headerLine);
        });

        $ok = curl_exec($ch);
        $err = curl_errno($ch);
        $info = curl_getinfo($ch);
        fclose($fp);
        if ($err) {
            @unlink($outTmp);
            curl_close($ch);
            return false;
        }
        curl_close($ch);
        return [
            'http_code' => $info['http_code'] ?? null,
            'effective_url' => $info['url'] ?? $url,
            'headers' => $responseHeaders,
            'content_length' => $info['download_content_length'] ?? null,
        ];
    }

    protected function parseHeaders(string $raw): array
    {
        $lines = preg_split('#\r\n#', trim($raw));
        $res = [];
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $res[strtolower(trim($k))] = trim($v);
            } else {
                $res['status-line'] = $line;
            }
        }
        return $res;
    }

    /** ----------------------- Parallel fetch utility ----------------------- */

    /**
     * Parallel fetch many URLs (returns map url=>filePath or url=>false)
     * Uses curl_multi with file streaming.
     */
    public static function fetchManyToCache(array $urls, array $opts = []): array
    {
        $limit = self::$config['parallel_limit'] ?? 6;
        $results = [];

        $multi = curl_multi_init();

        $chunks = array_chunk($urls, $limit);
        foreach ($chunks as $batch) {
            $handles = [];
            foreach ($batch as $url) {
                $f = new self($url);
                $key = $f->cacheKey($url);
                $tmp = $f->cachePathForKey($key) . '.tmp';
                $fp = fopen($tmp, 'w');
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_RETURNTRANSFER => false,
                    CURLOPT_FILE => $fp,
                    CURLOPT_CONNECTTIMEOUT => self::$config['connect_timeout'],
                    CURLOPT_TIMEOUT => self::$config['timeout'],
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                curl_multi_add_handle($multi, $ch);
                $handles[(int)$ch] = ['ch'=>$ch, 'fp'=>$fp, 'file'=>$f, 'tmp'=>$tmp];
            }

            // run loop
            do {
                curl_multi_exec($multi, $running);
                curl_multi_select($multi, 1.0);
            } while ($running > 0);

            // collect
            foreach ($handles as $idx => $h) {
                $ch = $h['ch'];
                $info = curl_getinfo($ch);
                $err = curl_errno($ch);
                fclose($h['fp']);
                $fobj = $h['file'];
                $final = $fobj->cachePathForKey($fobj->cacheKey($fobj->original));
                if ($err === 0) {
                    rename($h['tmp'], $final);
                    $meta = [
                        'original_url'=>$fobj->original,
                        'effective_url'=>$info['url'] ?? $fobj->original,
                        'http_code'=>$info['http_code'] ?? null,
                        'fetched_at'=>time(),
                        'cache_ttl'=>self::$config['default_ttl'],
                        'cached_until'=>time() + self::$config['default_ttl'],
                    ];
                    $fobj->saveMeta($fobj->metaPath($final), $meta);
                    $results[$fobj->original] = $final;
                } else {
                    @unlink($h['tmp']);
                    $results[$fobj->original] = false;
                }
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
            }
        }

        curl_multi_close($multi);
        return $results;
    }
}
