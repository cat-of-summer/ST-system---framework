<?php

namespace ST_system\Captcha\Drivers\Image;

class ImagickAdapter implements ImageAdapterInterface {

    public static function isAvailable(): bool {
        if (!class_exists('Imagick') || !class_exists('ImagickDraw')) return false;

        $formats = array_map('strtolower', \Imagick::queryFormats('PNG*'));

        return in_array('png', $formats, true);
    }

    public static function render(string $text, array $options): string {
        $width  = max(60, (int)($options['width']  ?? 240));
        $height = max(24, (int)($options['height'] ?? 80));
        $fonts  = array_values((array)($options['fonts'] ?? []));

        if (!$fonts)
            throw new \RuntimeException('ImagickAdapter: no TTF fonts available');

        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!$chars)
            throw new \RuntimeException('ImagickAdapter: empty challenge text');

        $size = (int)($options['font_size'] ?? (int)round($height * 0.52));
        $size = max(10, min($size, (int)round($height * 0.8)));

        $image = new \Imagick();

        try {
            $image->newImage($width, $height, new \ImagickPixel('rgb(245,245,245)'), 'png');

            $step = ($width - 12) / count($chars);
            $x    = 6.0;

            foreach ($chars as $char) {
                $draw = new \ImagickDraw();
                $draw->setFont($fonts[array_rand($fonts)]);
                $draw->setFontSize($size);
                $draw->setGravity(\Imagick::GRAVITY_WEST);
                $draw->setFillColor(new \ImagickPixel(sprintf(
                    'rgb(%d,%d,%d)', random_int(0, 90), random_int(0, 90), random_int(0, 90)
                )));

                $image->annotateImage($draw, $x, random_int(-4, 4), random_int(-24, 24), $char);
                $draw->destroy();

                $x += $step + random_int(-2, 2);
            }

            $amplitude = max(0, (int)($options['distortion'] ?? 4));

            if ($amplitude > 0) {
                $image->setImageVirtualPixelMethod(\Imagick::VIRTUALPIXELMETHOD_BACKGROUND);
                $image->setImageBackgroundColor(new \ImagickPixel('rgb(245,245,245)'));
                $image->waveImage($amplitude, max(24.0, $width / 2));

                $image->cropImage($width, $height, 0, (int)round(($image->getImageHeight() - $height) / 2));
                $image->setImagePage($width, $height, 0, 0);
            }

            $draw = new \ImagickDraw();
            $draw->setStrokeWidth(1);

            for ($i = 0, $lines = max(0, (int)($options['lines'] ?? 3)); $i < $lines; $i++) {
                $draw->setStrokeColor(new \ImagickPixel(sprintf(
                    'rgb(%d,%d,%d)', random_int(0, 150), random_int(0, 150), random_int(0, 150)
                )));

                $draw->line(
                    random_int(0, $width - 1), random_int(0, $height - 1),
                    random_int(0, $width - 1), random_int(0, $height - 1)
                );
            }

            $draw->setStrokeWidth(0);
            $noise = min(30, max(0, (int)($options['noise'] ?? 6)));

            for ($i = 0, $dots = (int)round($width * $height * $noise / 100); $i < $dots; $i++) {
                $draw->setFillColor(new \ImagickPixel(sprintf(
                    'rgb(%d,%d,%d)', random_int(0, 255), random_int(0, 255), random_int(0, 255)
                )));

                $draw->point(random_int(0, $width - 1), random_int(0, $height - 1));
            }

            $image->drawImage($draw);
            $draw->destroy();

            $contrast = (int)($options['contrast'] ?? 10);
            for ($i = 0; $i < (int)round(abs($contrast) / 10); $i++)
                $image->contrastImage(false);

            $image->setImageFormat('png');
            $blob = $image->getImageBlob();
        } finally {
            $image->clear();
            $image->destroy();
        }

        if ($blob === '')
            throw new \RuntimeException('ImagickAdapter: cannot encode PNG');

        return $blob;
    }

}
