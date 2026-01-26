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

    public function parseFilename(): array {
        $file = $this->file->getBasename();
        $extension = $this->file->getExtension();

        $weight = static::config('weight.regular');
        foreach (static::config('weight') as $key => $w)
            if (stripos($file, $key) !== false) {
                $weight = $w; 
                break; 
            }

        $style = 'normal';
        foreach (static::config('style') as $key => $s)
            if (stripos($file, $key) !== false) {
                $style = $s; 
                break; 
            }

        return [
            'weight' => $weight,
            'style' => $style,
            'format' => static::config("format.{$extension}") ?: $extension,
            'family' => trim(preg_replace( '/(?<!^)(?=[A-Z])/', ' ', preg_match('/^[A-Za-z]+/', $file, $matches) ? $matches[0] : $file)),
            'display' => 'swap'
        ];
    }

    public function toHTML(array $config = []): string {
        $config = array_merge(
            $this->parseFilename(),
            $config
        );

        return "
            <style>
                @font-face {
                    font-family: '{$config['family']}';
                    src: url('{$this->file->getRelativePath()}') format('{$config['format']}');
                    font-weight: {$config['weight']};
                    font-style: {$config['style']};
                    font-display: {$config['display']};
                }
            </style>
        ";
    }
}