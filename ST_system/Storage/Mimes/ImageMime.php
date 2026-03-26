<?php

namespace ST_system\Storage\Mimes;

use ST_system\Storage\Mimes\Mime;
use ST_system\Traits\HasConfig;
use ST_system\Cache;
use ST_system\Storage\File;

class ImageMime extends Mime {

    use HasConfig;

    protected static array $CONFIG = [
        'cache_dir' => '~/cache/',
        'convert' => [
            'config' => [
                'quality' => 90,
                'force' => false
            ]
        ],
        'formats' => [
            'gd' => [
                'jpg'  => [
                    'has_quality' => true, 
                    'function' => 'imagejpeg'
                ],
                'jpeg' => [
                    'has_quality' => true, 
                    'function' => 'imagejpeg'
                ],
                'png'  => [
                    'has_quality' => false, 
                    'function' => 'imagepng'
                ],
                // 'gif'  => [
                //     'has_quality' => false, 
                //     'function' => 'imagegif'
                // ],
                'webp' => [
                    'has_quality' => true, 
                    'function' => 'imagewebp'
                ],
                'bmp'  => [
                    'has_quality' => false, 
                    'function' => 'imagebmp'
                ],
                'gd'   => [
                    'has_quality' => false, 
                    'function' => 'imagegd'
                ],
                'gd2'  => [
                    'has_quality' => false, 
                    'function' => 'imagegd2'
                ],
            ],
            'imagick' => [
                'jpg' => true,
                'jpeg' => true,
                // 'gif' => true,
                'webp' => true,
                'bmp' => true,
                'tiff' => true,
                'ico' => true,
                'gd' => true,
                'gd2' => true,
            ]
        ],
        'resize' => [
            'config' => [
                'force' => false,
                'object-fit' => 'fill'
            ],
            'viewports' => [
                'xs' => 359.98,
                'sm' => 767.98,
                'md' => 1023.98,
                'lp' => 1279.98,
                'lg' => 1535.98,
                'dt' => 1919.98,
                'xl' => false,
            ],
            'sizes' => [
                'tiny' => 64,
                'small' => 128,
                'thumb' => 256,
                'preview' => 480,
                'medium' => 720,
                'large' => 1280,
                'huge' => 1600,
                'hd' => 1920
            ],
            'object-fit' => [
                'cover',
                'contain',
                'fill'
            ]
        ]
    ];

    private static string $IMAGE_DRIVER = '';

    public static function getImageDriver(): string {
        if (static::$IMAGE_DRIVER == '')
            static::$IMAGE_DRIVER = class_exists('Imagick')
                ? 'imagick'
                : ((function_exists('gd_info'))
                    ? 'gd'
                    : ''
                );

        return static::$IMAGE_DRIVER;
    }

    private Cache $cache;

    protected function __init(): void {
        static $is_sorted = false;
        if ($is_sorted === false) {
            $resize = static::config('resize');
            uasort($resize['viewports'], function ($a, $b) {
                if ($a === false) return 1;
                if ($b === false) return -1;

                return $a <=> $b;
            });
            asort($resize['sizes']);

            static::set_config(['resize' => $resize]);
            $is_sorted = true;
        }

        $this->cache = Cache::make($this->file->getPathname(), [
            'dir' => static::config('cache_dir') ?: File::config('cache.dir'),
            'file' => $this->file->getFilename(),
            'ttl' => -1,
        ]);
        
        if (static::getImageDriver() == '')
            throw new \Exception("No Imagick or GD");
    }

    public function toHTML(array $config = []): string {
        $attrs = array_merge(
            ['alt' => $this->file->getBasename()],
            $config,
            ['src' => $this->file->getRelativePath()]
        );

        return '<img '.static::getAttrString($attrs).' />';
    }

    public function toResponsive(array $config = [], array $attrs = []): string {
        $instance = $this->file->isUri()
            ? $this->file->fetch()
            : $this->file;
        
        $extension = $config['extension'] ?? 'webp';
        $viewport = $config['viewport'] ?? [];
        $quality = $config['quality'] ?? static::config('convert.config.quality');
        $sizes = $config['sizes'] ?? [];
        
        ['width' => $width] = $instance->getImageSize();

        $srcset = [];
        foreach (
            (empty($sizes)
                ? static::config('resize.sizes') 
                : array_intersect_key(
                    static::config('resize.sizes'),
                    array_filter($sizes)
                )
            ) as $px
        ) {
            $w = min($px, $width);

            $srcset[$w] = $instance->convert(array_merge(
                $w === $width ? [] : [
                    'width' => $w,
                ],
                [
                    'quality' => $quality,
                    'extension' => $extension,
                ]
            ))->getRelativePath()." {$w}w";

            if ($w === $width) break;
        }

        $sizes = [];
        foreach (array_reverse(static::config('resize.viewports'), true) as $display => $max) {
            $value = $viewport[$display] ?? ($max === false ? '100vw' : null);

            if (!$value) continue;

            $sizes[$max] = ($max !== false
                ? "(max-width:{$max}px) "
                : '').(is_string($value) ? $value : min($value, $max).'px');
        }

        $attrs = array_merge(
            ['alt' => $this->file->getBasename()],
            $attrs,
            ['src' => $srcset[$width]],
            ['srcset' => implode(', ', $srcset)],
            ['sizes' => implode(', ', array_reverse($sizes))]
        );

        return '<img '.static::getAttrString($attrs).' />';
    }

    public function getImageSize(): array {
        $instance = $this->file->isUri()
            ? $this->file->fetch()
            : $this->file;

        $cache = $this->cache->make($instance->getOriginal(true)->getPathname());

        if ($result = $cache->getMeta()['imageSize'])
            return $result;

        switch (static::$IMAGE_DRIVER) {
            case 'imagick':
                $image = new \Imagick();
                $image->pingImage($instance->getPathname());

                $width  = $image->getImageWidth();
                $height = $image->getImageHeight();

                $image->clear();
                $image->destroy();
            break;
            case 'gd':
                $info = getimagesize($instance->getPathname());

                if (!$info)
                    throw new \Exception("Couldn't resize the image");
                
                $width  = (int)$info[0];
                $height = (int)$info[1];
            break;
        }

        $result = [
            'width' => $width,
            'height' => $height,
            'side' => $width > $height ? $width : $height
        ];

        $cache->setMeta([
            'imageSize' => $result
        ]);

        return $result;
    }

    public function convert(array $config = []): File {
        $instance = $this->file->isUri()
            ? $this->file->fetch()
            : $this->file;

        $resize_config = (isset($config['width']) || isset($config['height']) || isset($config['side']))
            ? array_merge(
                static::config('resize.config'),
                $config
            )
            : [];

        $convert_config = (isset($config['extension']) || isset($config['quality']))
            ? array_merge(
                ['extension' => $instance->getExtension()],
                static::config('convert.config'),
                $config
            )
            : [];

        if (empty($resize_config) && empty($convert_config))
            return $instance;

        $quality = $convert_config['quality'];
        $old_file = $instance->getPathname();
        $old_extension = $instance->getExtension();
        $new_extension = $convert_config['extension'] ?? $old_extension;
        $prefix = '';
        
        if (!empty($resize_config)) {
            $resize_config['object-fit'] = in_array($resize_config['object-fit'], static::config('resize.object-fit'))
                ? $resize_config['object-fit']
                : static::config('resize.config.object-fit');

            foreach ([
                'width',
                'height',
                'side'
            ] as $side)
                if (isset($resize_config[$side])) {
                    if (isset(static::config('resize.sizes')[$resize_config[$side]]))
                        $resize_config[$side] = static::config('resize.sizes')[$resize_config[$side]];
                    elseif (substr($resize_config[$side], -2) == 'px')
                        $resize_config[$side] = (int)substr($resize_config[$side], 0, -2);
                    else
                        $resize_config[$side] = (int)$resize_config[$side];

                    if (!$resize_config[$side]) unset($resize_config[$side]);
                }
        
            [
                'width' => $width,
                'height' => $height
            ] = $instance->getImageSize();
                    
            if (!isset($resize_config['width']) && !isset($resize_config['height'])) {
                if (!isset($resize_config['side']))
                    throw new \Exception('Не переданы размеры!');
                
                $resize_config[$width > $height ? 'width' : 'height'] = $resize_config['side'];
            }
            
            if (!isset($resize_config['width']) || !isset($resize_config['height'])) {
                if (!isset($resize_config['width']))
                    $resize_config['width'] = (int)($resize_config['height'] / $height * $width);
                else
                    $resize_config['height'] = (int)($resize_config['width'] / $width * $height);
                
                $offset = ['height' => 0, 'width' => 0];
                $dst = $resize_config;
            } else {
                $scaleX = $resize_config['width'] / $width;
                $scaleY = $resize_config['height'] / $height;

                switch ($resize_config['object-fit']) {
                    case 'contain':
                        $scale = min($scaleX, $scaleY);
                        $dst['width'] = (int)($width * $scale);
                        $dst['height'] = (int)($height * $scale);

                        $offset = ['height' => (int)(abs($resize_config['height'] - $height) / 2), 'width' => (int)(abs($resize_config['width'] - $width) / 2)];
                        break;
                    case 'cover':
                        $scale = max($scaleX, $scaleY);
                        $dst['width'] = (int)($width * $scale);
                        $dst['height'] = (int)($height * $scale);

                        $offset = ['height' => 0, 'width' => 0];
                        break;
                    case 'fill':
                        $dst = $resize_config;

                        $offset = ['height' => 0, 'width' => 0];
                        break;
                }
            }

            $prefix = "{$resize_config['height']}x{$resize_config['width']}_{$quality}_{$resize_config['object-fit']}_";

            $resize_config = [
                'src' => [
                    'height' => $height,
                    'width' => $width
                ],
                'dst' => [
                    'height' => $dst['height'],
                    'width' => $dst['width']
                ],
                'canvas' => [
                    'height' => $resize_config['height'],
                    'width' => $resize_config['width']
                ],
                'offset' => [
                    'height' => $offset['height'],
                    'width' => $offset['width']
                ]
            ];
        }

        $cache = $this->cache->make($instance->getOriginal(true)->getPathname(), [
            'file' => $prefix.$instance->getBasename().'.'.$new_extension
        ]);

        if (($config['force'] ?? false) || !is_file($cache->file) || $cache->getMeta()['modified_at'] < $cache->getMeta($instance->getFilename())['modified_at']) {
            $cache->initDir();

            switch (static::$IMAGE_DRIVER) {
                case 'imagick':
                    foreach ([$old_extension, $new_extension] as $ext)
                        if (!isset(static::config('formats.imagick')[$ext]))
                            throw new \Exception("The format {$ext} is not supported");

                    $image = new \Imagick($old_file);
                break;
                case 'gd':
                    foreach ([$old_extension, $new_extension] as $ext)
                        if (!isset(static::config('formats.gd')[$ext]))
                            throw new \Exception("The format {$ext} is not supported");

                    $image = imagecreatefromstring($instance->getContents());

                    if (!$image)
                        throw new \Exception("Couldn't convert the image");
                break;
            }

            if (!empty($resize_config))
                $image = $this->resizeImage($image, $resize_config);

            $image = $this->convertImage($image, [
                'extension' => $new_extension,
                'quality' => $quality,
                'file' => $cache->file
            ]);
    
            switch (static::$IMAGE_DRIVER) {
                case 'imagick':
                    $image->clear();
                    $image->destroy();
                break;
                case 'gd':
                    imagedestroy($image);
                break;
            }

            $cache->setMeta([]);
        }

        return $instance->make($cache->file);
    }

    private function convertImage(object $image, array $config = []): object {
        switch (static::$IMAGE_DRIVER) {
            case 'imagick':
                $image->setImageFormat($config['extension']);
                $image->setImageCompressionQuality($config['quality']);

                $image->writeImage($config['file']);
            break;
            case 'gd':
                $function = static::config('formats.gd')[$config['extension']]['function'];

                if ($config['extension'] === 'webp' && !imageistruecolor($image)) {

                    $trueColor = imagecreatetruecolor(
                        imagesx($image),
                        imagesy($image)
                    );

                    imagealphablending($trueColor, false);
                    imagesavealpha($trueColor, true);

                    imagecopy(
                        $trueColor,
                        $image,
                        0, 0, 0, 0,
                        imagesx($image),
                        imagesy($image)
                    );

                    $image = $trueColor;
                }

                $function(...[
                    $image,
                    $config['file'],
                    ...(!static::config('formats.gd')[$config['extension']]['has_quality']
                        ? []
                        : [$config['quality']]
                    )
                ]);
            break;
        }

        return $image;
    }

    private function resizeImage(object $image_src, array $resize_config): object {
        [
            'canvas' => $canvas,
            'src' => $src,
            'dst' => $dst,
            'offset' => $offset
        ] = $resize_config;

        switch (static::$IMAGE_DRIVER) {
            case 'imagick':
                $image_dst = new \Imagick();
                $image_dst->newImage($canvas['width'], $canvas['height'], new \ImagickPixel('transparent'));

                $image_src->cropImage($src['width'], $src['height'], 0, 0);
                $image_src->resizeImage($dst['width'], $dst['height'], \Imagick::FILTER_LANCZOS, 1);

                $image_dst->compositeImage($image_src, \Imagick::COMPOSITE_OVER, $offset['width'], $offset['height']);

                $image_src->clear();
                $image_src->destroy();
            break;
            case 'gd':
                $image_dst = imagecreatetruecolor($canvas['width'], $canvas['height']);
                
                imagealphablending($image_dst, false);
                imagesavealpha($image_dst, true);
                imagefill($image_dst, 0, 0, imagecolorallocatealpha($image_dst, 0, 0, 0, 127));

                imagecopyresampled(
                    $image_dst,
                    $image_src,
                    $offset['width'], $offset['height'],
                    0, 0,
                    $dst['width'], $dst['height'],
                    $src['width'], $src['height']
                );

                imagedestroy($image_src);
            break;
        }

        return $image_dst;
    }
}