<?php

namespace ST_system\Storage\Mimes;

use ST_system\Storage\Mimes\Mime;
use ST_system\Traits\HasConfig;

class FontMime extends Mime {

    use HasConfig;

    protected static array $CONFIG = [
        'weight' => [
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
        'style' => [
            'italic' => 'italic',
            'oblique' => 'oblique'
        ],
        'format' => [
            'woff2' => 'woff2',
            'woff'  => 'woff',
            'ttf'   => 'truetype',
            'otf'   => 'opentype',
            'eot'   => 'embedded-opentype'
        ]
    ];

    public function toHTML(array $config = []): string {
        $file = $this->file->getBasename();
        $extension = $this->file->getExtension();

        if (!isset($config['weight'])) {
            $weight = static::config('weight.regular');
            foreach (static::config('weight') as $key => $w)
                if (stripos($file, $key) !== false) {
                    $weight = $w; 
                    break; 
                }
        } else $weight = $config['weight'];

        if (!isset($config['style'])) {
            $style = 'normal';
            foreach (static::config('style') as $key => $s)
                if (stripos($file, $key) !== false) {
                    $style = $s; 
                    break; 
                }
        } else $style = $config['style'];

        $format = $config['format'] ?? static::config("format.{$extension}") ?: $extension;
        $font_family = $config['family'] ?? trim(preg_replace( '/(?<!^)(?=[A-Z])/', ' ', preg_match('/^[A-Za-z]+/', $file, $matches) ? $matches[0] : $file));
        $src = $this->file->getRelativePath();
        $display = $config['display'] ?? 'swap';

        return <<<HTML
            <style>
                @font-face {
                        font-family: '{$font_family}';
                        src: url('{$src}') format('{$format}');
                        font-weight: {$weight};
                        font-style: {$style};
                        font-display: {$display};
                    }
            </style>
        HTML;
    }
}