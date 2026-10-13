<?php

namespace ST_system\Captcha\Drivers\Image;

class GdAdapter implements ImageAdapterInterface, PuzzleAdapterInterface {

    public static function isAvailable(): bool {
        return function_exists('gd_info')
            && function_exists('imagettftext')
            && (imagetypes() & IMG_PNG) === IMG_PNG;
    }

    public static function puzzle(string $file, array $options): string {
        $w     = (int)$options['width'];
        $h     = (int)$options['height'];
        $rows  = (int)$options['rows'];
        $cols  = (int)$options['cols'];
        $b     = (int)$options['bleed'];
        $order = array_values((array)$options['order']);

        $raw = @file_get_contents($file);
        $src = $raw === false ? false : @imagecreatefromstring($raw);

        if ($src === false)
            throw new \RuntimeException("GdAdapter: cannot read image '{$file}'");

        // Cover со случайным приближением 1.00–1.15 и сдвигом: каждая выдача — свой кадр.
        $sw    = imagesx($src);
        $sh    = imagesy($src);
        $scale = max($w / $sw, $h / $sh) * random_int(100, 115) / 100;
        $cropW = max(1, (int)floor($w / $scale));
        $cropH = max(1, (int)floor($h / $scale));

        $image = imagecreatetruecolor($w, $h);
        imagecopyresampled(
            $image, $src, 0, 0,
            random_int(0, max(0, $sw - $cropW)), random_int(0, max(0, $sh - $cropH)),
            $w, $h, $cropW, $cropH
        );
        imagedestroy($src);

        @imagefilter($image, IMG_FILTER_BRIGHTNESS, random_int(-12, 12));
        @imagefilter($image, IMG_FILTER_CONTRAST, random_int(-10, 10));

        $pad = self::mirrorPad($image, $w, $h, $b);
        imagedestroy($image);

        $cw       = intdiv($w, $cols);
        $ch       = intdiv($h, $rows);
        $cellW    = $cw + 2 * $b;
        $cellH    = $ch + 2 * $b;
        $outlines = (array)($options['outlines'] ?? []);
        $sprite   = imagecreatetruecolor($cols * $cellW, $rows * $cellH);

        if ($outlines) {
            imagealphablending($sprite, false);
            imagesavealpha($sprite, true);
            imagefilledrectangle($sprite, 0, 0, $cols * $cellW, $rows * $cellH, imagecolorallocatealpha($sprite, 0, 0, 0, 127));
        }

        foreach ($order as $k => $piece) {
            $dx = ($k % $cols) * $cellW;
            $dy = intdiv($k, $cols) * $cellH;
            $sx = ($piece % $cols) * $cw;
            $sy = intdiv($piece, $cols) * $ch;

            if (isset($outlines[$piece])) self::cutPiece($sprite, $pad, $dx, $dy, $sx, $sy, $cellW, $cellH, $outlines[$piece]);
            else                          imagecopy($sprite, $pad, $dx, $dy, $sx, $sy, $cellW, $cellH);
        }

        imagedestroy($pad);

        ob_start();
        if ($outlines) {
            // Кусочкам нужна прозрачность: WebP с альфой, без него — PNG.
            if (function_exists('imagewebp') && defined('IMG_WEBP') && (imagetypes() & IMG_WEBP)) imagewebp($sprite, null, max(40, min(95, (int)($options['quality'] ?? 82))));
            else                                                                       imagepng($sprite, null, 9);
        } elseif ((imagetypes() & IMG_JPG) === IMG_JPG) {
            imagejpeg($sprite, null, max(40, min(95, (int)($options['quality'] ?? 82))));
        } else {
            imagepng($sprite, null, 9);
        }
        $bytes = (string)ob_get_clean();

        imagedestroy($sprite);

        if ($bytes === '')
            throw new \RuntimeException('GdAdapter: cannot encode the puzzle sprite');

        return $bytes;
    }

    /** Сглаживание края: маска рисуется в SS раз крупнее и ужимается. */
    private const SS = 3;

    /**
     * Кусочек по контуру: в ячейку спрайта копируется только то, что внутри многоугольника,
     * край — с полупрозрачным сглаживанием.
     */
    private static function cutPiece($sprite, $pad, int $dx, int $dy, int $sx, int $sy, int $w, int $h, array $outline): void {
        $big = imagecreatetruecolor($w * self::SS, $h * self::SS);
        imagefilledrectangle($big, 0, 0, $w * self::SS, $h * self::SS, imagecolorallocate($big, 0, 0, 0));

        $flat = [];
        foreach ($outline as [$x, $y]) {
            $flat[] = (int)round($x * self::SS);
            $flat[] = (int)round($y * self::SS);
        }

        if (PHP_VERSION_ID >= 80000) imagefilledpolygon($big, $flat, imagecolorallocate($big, 255, 255, 255));
        else                         imagefilledpolygon($big, $flat, count($flat) / 2, imagecolorallocate($big, 255, 255, 255));

        $mask = imagecreatetruecolor($w, $h);
        imagecopyresampled($mask, $big, 0, 0, 0, 0, $w, $h, $w * self::SS, $h * self::SS);
        imagedestroy($big);

        for ($y = 0; $y < $h; $y++)
            for ($x = 0; $x < $w; $x++) {
                $cover = imagecolorat($mask, $x, $y) & 0xFF;
                if ($cover === 0) continue;

                $rgb = imagecolorat($pad, $sx + $x, $sy + $y);
                imagesetpixel($sprite, $dx + $x, $dy + $y,
                    ((127 - intdiv($cover * 127, 255)) << 24) | ($rgb & 0xFFFFFF));
            }

        imagedestroy($mask);
    }

    /** Картинка с зеркальным полем $b со всех сторон: у краёв в язычках — продолжение, а не пустота. */
    private static function mirrorPad($image, int $w, int $h, int $b) {
        $pad = imagecreatetruecolor($w + 2 * $b, $h + 2 * $b);
        imagecopy($pad, $image, $b, $b, 0, 0, $w, $h);

        if ($b <= 0) return $pad;

        $flipH = self::flipped($image, $w, $h, IMG_FLIP_HORIZONTAL);
        $flipV = self::flipped($image, $w, $h, IMG_FLIP_VERTICAL);
        $flipB = self::flipped($image, $w, $h, IMG_FLIP_BOTH);

        imagecopy($pad, $flipH, 0,      $b,      $w - $b, 0,       $b, $h);
        imagecopy($pad, $flipH, $b + $w, $b,     0,       0,       $b, $h);
        imagecopy($pad, $flipV, $b,      0,      0,       $h - $b, $w, $b);
        imagecopy($pad, $flipV, $b,      $b + $h, 0,      0,       $w, $b);
        imagecopy($pad, $flipB, 0,       0,      $w - $b, $h - $b, $b, $b);
        imagecopy($pad, $flipB, $b + $w, 0,      0,       $h - $b, $b, $b);
        imagecopy($pad, $flipB, 0,       $b + $h, $w - $b, 0,      $b, $b);
        imagecopy($pad, $flipB, $b + $w, $b + $h, 0,      0,       $b, $b);

        imagedestroy($flipH);
        imagedestroy($flipV);
        imagedestroy($flipB);

        return $pad;
    }

    private static function flipped($image, int $w, int $h, int $mode) {
        $copy = imagecreatetruecolor($w, $h);
        imagecopy($copy, $image, 0, 0, 0, 0, $w, $h);
        imageflip($copy, $mode);

        return $copy;
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
