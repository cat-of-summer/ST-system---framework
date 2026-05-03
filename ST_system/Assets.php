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
                    if ($p !== '' && strncmp($p, '.', 1) === 0) {
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

use ST_system\Traits\HasConfig;
use ST_system\Storage\File;

final class Assets {

    use HasConfig;

    protected static function getDefaultConfig(): array {
        return [
            'default_buffer' => 'head',
            'bufferization' => true
        ];
    }

    private static $buffers = [];
    private static $stack = [];

    private static function current_buffer() {
        return end(self::$stack) ?: static::config('default_buffer');
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

    public static function mount(string $name) {            
        self::ensure_buffer($name);

        if (self::$buffers[$name]['started'])
            throw new \RuntimeException("Buffer '{$name}' already started.");

        self::$buffers[$name]['started'] = true;
        array_push(self::$stack, $name);
    
        if (static::config('bufferization')) {
            echo self::unpack_buffer($name);

            ob_start();
        } else {
            echo '<!-- '.__CLASS__.'::mount("'.$name.'") -->';
        }
    }

    public static function render_html(string $html) {
        if (static::config('bufferization'))
            return;

        foreach (self::$buffers as $name => ['content' => &$content, 'started' => &$started]) {
            $html = str_replace('<!-- '.__CLASS__.'::mount("'.$name.'") -->', $content, $html);
            $started = false;
            $content = '';
        }

        return $html;
    }

    public static function render(string $buffer) {

        if (!static::config('bufferization')) {
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

    private File $file;
    private string $buffer;

    public function __construct(string $path, string $buffer = '') {
        $this->file = File::make($path);
        
        $this->buffer = $buffer != '' ? $buffer : self::current_buffer();
    }

    public static function add_resource() {
        
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
            $weight = static::config('fonts')['weights_map']['regular'];
            foreach (static::config('fonts')['weights_map'] as $key => $w)
                if (stripos($file_name, $key) !== false) {
                    $weight = $w; 
                    break; 
                }
        } else $weight = $PARAMS['font-weight'];

        if (!isset($PARAMS['font-style'])) {
            $style = 'normal';
            foreach (static::config('fonts')['styles_map'] as $key => $s)
                if (stripos($file_name, $key) !== false) {
                    $style = $s; 
                    break; 
                }
        } else $style = $PARAMS['font-style'];

        $format = isset($PARAMS['format']) ? $PARAMS['format'] : (isset(static::config('extensions_map')[$file_extension]) ? static::config('extensions_map')[$file_extension] : $file_extension);
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