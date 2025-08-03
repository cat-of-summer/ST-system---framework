<?php

namespace ST_system;

class Assets {

    private static $settings = [
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
            'extensions_map' => [
                'woff2' => 'woff2',
                'woff'  => 'woff',
                'ttf'   => 'truetype',
                'otf'   => 'opentype',
                'eot'   => 'embedded-opentype',
                'svg'   => 'svg',
            ],
        ]
    ];

    private $assets = [
        'header' => [
            'css' => [],
            'js' => [],
            'string' => [],
            'fonts' => []
        ],
        'footer' => [
            'js' => [],
            'string' => []
        ]
    ];

    public $related_path = '';

    public function __construct(string $related_path = '') {
        $this->related_path = $related_path;
    }

    public function __call($name, $arguments) {
        switch ($name) {
            case 'svg':
                $arguments[0] = $this->related_path.$arguments[0];
                return self::_svg(...$arguments);
            default: throw new \BadMethodCallException("Метод $name не найден");
        }
    }

    public static function __callStatic($name, $arguments) {
        switch ($name) {
            case 'svg':
                return self::_svg(...$arguments);
            default: throw new \BadMethodCallException("Метод $name не найден");
        }
    }


    private static function _svg(string $full_path, array $attr = []) {
        static $id = 0;

        if (!file_exists($full_path))
            throw new \Exception("SVG file not found: " . $full_path);

        $svg = @file_get_contents($full_path);

        if ($svg === false)
            throw new \Exception("Failed to read SVG file: ".$full_path);

        if (strpos($svg, 'id="') !== false) {
            $id++;
            $svg = preg_replace('/id="([^"]+)"/', 'id="${1}_' . $id . '"', $svg);
            $svg = preg_replace('/url\(#([^"]+)\)/', 'url(#${1}_' . $id . ')', $svg);
            $svg = preg_replace('/href="#([^"]+)"/', 'href="#${1}_' . $id . '"', $svg);
        }

        if (!empty($attr)) {
            if (preg_match('/<svg\s+([^>]+)>/i', $svg, $matches)) {
                $existing_attrs = $matches[1];

                preg_match_all('/(\w+)\s*=\s*"([^"]*)"/', $existing_attrs, $parsed);
                $svg_attrs = array_merge(array_combine($parsed[1], $parsed[2]), $attr);

                $new_attrs = implode(' ', array_map(
                    fn($k, $v) => sprintf('%s="%s"', $k, $v),
                    array_keys($svg_attrs),
                    $svg_attrs
                )) . ' ';

                $svg = preg_replace('/<svg\s+[^>]+>/i', '<svg ' . trim($new_attrs) . '>', $svg);
            } else throw new \Exception("SVG tag not found in: " . $full_path);
        }

        return $svg;
    }
    

    private static function collect_files(string $dir, array $extensions_map) {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file)
            if ($file->isFile() && in_array(pathinfo($file->getFilename(), PATHINFO_EXTENSION), $extensions_map))
                $files[] = $file->getPathname();

        return $files;
    }

    private static function file_path_to_url(string $file_path) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];

        $relative = ltrim(str_replace('\\', '/', ltrim(str_replace(realpath($_SERVER['DOCUMENT_ROOT']), '', realpath($file_path)), '/')), '/');

        return "{$protocol}://{$host}/{$relative}";
    }


    public function add_font(string $src, array $PARAMS = []) {

        $src = $this->related_path.$src;

        $parse_font = function ($file_path, $is_url = false) use ($PARAMS) {
            $file_name = $is_url
                ? pathinfo(parse_url($file_path, PHP_URL_PATH), PATHINFO_FILENAME)
                : pathinfo($file_path, PATHINFO_FILENAME);
            $file_extension = pathinfo($file_path, PATHINFO_EXTENSION);

            if (!isset($PARAMS['font-weight'])) {
                $weight = self::$settings['fonts']['weights_map']['regular'];
                foreach (self::$settings['fonts']['weights_map'] as $key => $w)
                    if (stripos($file_name, $key) !== false) {
                        $weight = $w; 
                        break; 
                    }
            } else $weight = $PARAMS['font-weight'];

            if (!isset($PARAMS['font-style'])) {
                $style = 'normal';
                foreach (self::$settings['fonts']['styles_map'] as $key => $s)
                    if (stripos($file_name, $key) !== false) {
                        $style = $s; 
                        break; 
                    }
            } else $style = $PARAMS['font-style'];

            $format = isset($PARAMS['format']) ? $PARAMS['format'] : (isset(self::$settings['fonts']['extensions_map'][$file_extension]) ? self::$settings['fonts']['extensions_map'][$file_extension] : $file_extension);
            $font_family = isset($PARAMS['font-family']) ? $PARAMS['font-family'] : trim(preg_replace( '/(?<!^)(?=[A-Z])/', ' ', preg_match('/^[A-Za-z]+/', $file_name, $matches) ? $matches[0] : $file_name));
            $src = $is_url
                ? $file_path
                : (isset($PARAMS['src']) ? $PARAMS['src'] : self::file_path_to_url($file_path));
            $display = isset($PARAMS['font-display']) ? $PARAMS['font-display'] : 'swap';

            return <<<HTML
                @font-face {
                    font-family: '{$font_family}';
                    src: url('{$src}') format('{$format}');
                    font-weight: {$weight};
                    font-style: {$style};
                    font-display: {$display};
                }
            HTML;
        };

        if (file_exists($src)) {
            $files = is_dir($src)
                ? self::collect_files($src, array_keys(self::$settings['fonts']['extensions_map']))
                : [$src];

            foreach ($files as $file_path)
                $this->assets['header']['fonts'][$file_path] = $parse_font($file_path);

        } elseif (filter_var($src, FILTER_VALIDATE_URL)) {
            $headers = get_headers($src, 1);
            $this->assets['header']['fonts'][$src] = strpos(isset($headers['Content-Type']) ? $headers['Content-Type'] : '', 'text/css') !== false
                ? $this->assets['header']['fonts'][$src] = @file_get_contents($src) ?: ''
                : $this->assets['header']['fonts'][$src] = $parse_font($src, true);

        } else throw new \Exception("Некорректный путь к файлу шрифта."); 
    }

    public function render() {
        if (!empty($this->assets['header']['fonts']))
            echo '<style>'.implode(PHP_EOL, $this->assets['header']['fonts']).'</style>';
    }

}