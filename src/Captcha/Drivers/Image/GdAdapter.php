<?php

namespace ST_system\Captcha\Drivers\Image;

class GdAdapter implements ImageAdapterInterface {

    public static function isAvailable(): bool {
        return function_exists('gd_info')
            && function_exists('imagettftext')
            && (imagetypes() & IMG_PNG) === IMG_PNG;
    }

    public static function render(string $text, array $options): string {
        $width  = max(60, (int)($options['width']  ?? 240));
        $height = max(24, (int)($options['height'] ?? 80));
        $fonts  = array_values((array)($options['fonts'] ?? []));

        if (!$fonts)
            throw new \RuntimeException('GdAdapter: no TTF fonts available');

        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!$chars)
            throw new \RuntimeException('GdAdapter: empty challenge text');

        $image = imagecreatetruecolor($width, $height);
        if ($image === false)
            throw new \RuntimeException('GdAdapter: cannot create image');

        $background = imagecolorallocate($image, 245, 245, 245);
        imagefilledrectangle($image, 0, 0, $width, $height, $background);

        $size = (int)($options['font_size'] ?? (int)round($height * 0.52));
        $size = max(10, min($size, (int)round($height * 0.8)));
        $step = ($width - 12) / count($chars);

        $x = 6.0;

        foreach ($chars as $char) {
            $font  = $fonts[array_rand($fonts)];
            $angle = random_int(-24, 24);
            $ink   = imagecolorallocate($image, random_int(0, 90), random_int(0, 90), random_int(0, 90));

            $box = @imagettfbbox($size, $angle, $font, $char);

            if ($box === false) {
                imagedestroy($image);
                throw new \RuntimeException("GdAdapter: cannot measure glyph with font '{$font}'");
            }

            $glyph = $box[1] - $box[5];
            $y     = (int)round(($height - $glyph) / 2 - $box[5]) + random_int(-3, 3);

            if (@imagettftext($image, $size, $angle, (int)round($x), $y, $ink, $font, $char) === false) {
                imagedestroy($image);
                throw new \RuntimeException("GdAdapter: cannot render glyph with font '{$font}'");
            }

            $x += $step + random_int(-2, 2);
        }

        $amplitude = max(0, (int)($options['distortion'] ?? 4));
        $copy      = $amplitude > 0 ? imagecreatetruecolor($width, $height) : false;

        if ($copy !== false) {
            imagefilledrectangle($copy, 0, 0, $width, $height, $background);

            $frequency = 2 * M_PI / max(24.0, $width / 2);
            $phase     = random_int(0, 628) / 100;

            for ($x = 0; $x < $width; $x++) {
                $shift = (int)round(sin($x * $frequency + $phase) * $amplitude);

                imagecopy(
                    $copy, $image,
                    $x, max(0, $shift),
                    $x, max(0, -$shift),
                    1, $height - abs($shift)
                );
            }

            imagedestroy($image);
            $image = $copy;
        }

        for ($i = 0, $lines = max(0, (int)($options['lines'] ?? 3)); $i < $lines; $i++)
            imageline(
                $image,
                random_int(0, $width - 1), random_int(0, $height - 1),
                random_int(0, $width - 1), random_int(0, $height - 1),
                imagecolorallocate($image, random_int(0, 150), random_int(0, 150), random_int(0, 150))
            );

        $noise = min(30, max(0, (int)($options['noise'] ?? 6)));

        for ($i = 0, $dots = (int)round($width * $height * $noise / 100); $i < $dots; $i++)
            imagesetpixel(
                $image,
                random_int(0, $width - 1),
                random_int(0, $height - 1),
                imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255))
            );

        $contrast = (int)($options['contrast'] ?? 10);
        if ($contrast !== 0) @imagefilter($image, IMG_FILTER_CONTRAST, -$contrast);

        ob_start();
        imagepng($image, null, 9);
        $png = (string)ob_get_clean();

        imagedestroy($image);

        if ($png === '')
            throw new \RuntimeException('GdAdapter: cannot encode PNG');

        return $png;
    }

}
