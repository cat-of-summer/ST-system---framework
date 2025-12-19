<?php

namespace ST_system\Storage\Mimes;

use ST_system\Storage\Mimes\Mime;
use ST_system\Traits\HasConfig;
use ST_system\Storage\File;

class ImageMime extends Mime {

    use HasConfig;

    protected static array $CONFIG = [
        'convertTo' => [
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
                'gif'  => [
                    'has_quality' => false, 
                    'function' => 'imagegif'
                ],
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
                'gif' => true,
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
                'quality' => 90,
                'force' => false,
                'object-fit' => 'fill'
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

    protected function __init(): void {
        static $is_cache_init = false;

        if (!$is_cache_init) {
            static::set_config([
                'cache_dir' => File::prepare_path(rtrim(File::config('cache_dir'), '/').'/image_cache/')
            ]);

            if (!is_dir(static::config('cache_dir'))) {
                @mkdir(static::config('cache_dir'), 0775, true);

                if (!is_dir(static::config('cache_dir')))
                    throw new \RuntimeException("Cannot create cache directory");
            }

            $is_cache_init = true;
        }
        
        if (static::$IMAGE_DRIVER == '')
            static::$IMAGE_DRIVER = class_exists('Imagick')
                ? 'imagick'
                : ((function_exists('gd_info'))
                    ? 'gd'
                    : throw new \Exception("No Imagick or GD")
                );
    }

    public function toHTML(array $config = []): string { return "<img src='{$this->file->getRelativePath()}' alt='{$this->file->getBasename()}' />"; }

    public function test() {
        $imgs = [];
        foreach (static::config('resize.sizes') as $size => $v) {
            $imgs[] = $this->file->convert([
                'extension' => 'webp',
                'side' => $size
            ]);
        }

        return implode('<br>', array_map(fn($file) => $file->toHTML(), $imgs));
    }

    public function convert(array $config = []): File {
        $instance = $this->file->isUri()
            ? $this->file->fetch()
            : $this->file;

        $resize_config = (isset($config['width']) || isset($config['height']) || isset($config['side']))
            ? [
                ...static::config('resize.config'),
                ...$config
            ]
            : [];

        $convert_config = (isset($config['extension']) || isset($config['quality']))
            ? [
                'extension' => $instance->getExtension(),
                ...static::config('convertTo.config'),
                ...$config
            ]
            : [];

        if (empty($resize_config) && empty($convert_config))
            return $instance;

        $quality = $convert_config['quality'] ?? $resize_config['quality'];
        $old_file = $instance->getPathname();
        $old_extension = $instance->getExtension();
        $new_extension = $convert_config['extension'] ?? $old_extension;
        $cache_directory = static::config('cache_dir').'/'.md5($old_file).'/';
        $prefix = '';
        
        if (!is_dir($cache_directory)) {
            mkdir($cache_directory, 0775, true);

            if (!is_dir($cache_directory))
                throw new \RuntimeException("Cannot create cache directory");
        }

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

            $prefix = "{$resize_config['height']}x{$resize_config['width']}_{$resize_config['object-fit']}_";

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

        $new_file = $cache_directory.$prefix.$instance->getBasename().'.'.$new_extension;

        if (($config['force'] ?? false) || !is_file($new_file)) {
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
                'file' => $new_file
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
        }

        return $instance->make($new_file);
    }

    private function convertImage(object $image, array $config = []): object {
        switch (static::$IMAGE_DRIVER) {
            case 'imagick':
                $image->setImageFormat($config['extension']);
                $image->setImageCompressionQuality($config['quality']);

                $image->writeImage($config['file']);
            break;
            case 'gd':
                static::config('formats.gd')[$config['extension']]['function'](...[
                    $image,
                    $config['file'],
                    ...(!static::config('formats.gd')[$config['extension']]['has_quality'] ? [] : [$config['quality']])
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