<?php

namespace ST_system\Captcha\Drivers\Image;

class ImagickAdapter implements ImageAdapterInterface, PuzzleAdapterInterface {

    public static function isAvailable(): bool {
        if (!class_exists('Imagick') || !class_exists('ImagickDraw')) return false;

        $formats = array_map('strtolower', \Imagick::queryFormats('PNG*'));

        return in_array('png', $formats, true);
    }

    public static function puzzle(string $file, array $options): string {
        $w     = (int)$options['width'];
        $h     = (int)$options['height'];
        $rows  = (int)$options['rows'];
        $cols  = (int)$options['cols'];
        $b     = (int)$options['bleed'];
        $order = array_values((array)$options['order']);

        $image  = new \Imagick();
        $pad    = null;
        $sprite = null;

        try {
            if (!@$image->readImage($file))
                throw new \RuntimeException("ImagickAdapter: cannot read image '{$file}'");

            $image->setImageColorspace(\Imagick::COLORSPACE_SRGB);
            $image->setIteratorIndex(0);

            // Cover со случайным приближением 1.00–1.15 и сдвигом: каждая выдача — свой кадр.
            $sw    = $image->getImageWidth();
            $sh    = $image->getImageHeight();
            $scale = max($w / $sw, $h / $sh) * random_int(100, 115) / 100;
            $cropW = max(1, (int)floor($w / $scale));
            $cropH = max(1, (int)floor($h / $scale));

            $image->cropImage($cropW, $cropH, random_int(0, max(0, $sw - $cropW)), random_int(0, max(0, $sh - $cropH)));
            $image->setImagePage(0, 0, 0, 0);
            $image->resizeImage($w, $h, \Imagick::FILTER_LANCZOS, 1);
            $image->modulateImage(100 + random_int(-8, 8), 100 + random_int(-6, 6), 100);

            $pad = self::mirrorPad($image, $w, $h, $b);

            $cw       = intdiv($w, $cols);
            $ch       = intdiv($h, $rows);
            $cellW    = $cw + 2 * $b;
            $cellH    = $ch + 2 * $b;
            $outlines = (array)($options['outlines'] ?? []);
            $format   = $outlines ? (in_array('WEBP', \Imagick::queryFormats('WEBP'), true) ? 'webp' : 'png') : 'jpg';
            $sprite   = new \Imagick();
            $sprite->newImage($cols * $cellW, $rows * $cellH, new \ImagickPixel($outlines ? 'transparent' : 'black'), $format);

            foreach ($order as $k => $piece) {
                $cell = clone $pad;
                $cell->cropImage($cellW, $cellH, ($piece % $cols) * $cw, intdiv($piece, $cols) * $ch);
                $cell->setImagePage(0, 0, 0, 0);

                if (isset($outlines[$piece])) {
                    // Кусочек по контуру: всё вне многоугольника — прозрачное.
                    $mask = new \Imagick();
                    $mask->newImage($cellW, $cellH, new \ImagickPixel('transparent'), 'png');
                    $draw = new \ImagickDraw();
                    $draw->setFillColor(new \ImagickPixel('white'));
                    $draw->polygon(array_map(static fn(array $p): array => ['x' => $p[0], 'y' => $p[1]], $outlines[$piece]));
                    $mask->drawImage($draw);
                    $draw->destroy();

                    $cell->setImageAlphaChannel(\Imagick::ALPHACHANNEL_SET);
                    $cell->compositeImage($mask, \Imagick::COMPOSITE_DSTIN, 0, 0);
                    $mask->destroy();
                }

                $sprite->compositeImage($cell, $outlines ? \Imagick::COMPOSITE_OVER : \Imagick::COMPOSITE_COPY, ($k % $cols) * $cellW, intdiv($k, $cols) * $cellH);
                $cell->destroy();
            }

            $sprite->setImageFormat($format);
            $sprite->setImageCompressionQuality(max(40, min(95, (int)($options['quality'] ?? 82))));
            $sprite->stripImage();
            $bytes = $sprite->getImageBlob();
        } finally {
            $image->destroy();
            if ($pad)    $pad->destroy();
            if ($sprite) $sprite->destroy();
        }

        if ($bytes === '')
            throw new \RuntimeException('ImagickAdapter: cannot encode the puzzle sprite');

        return $bytes;
    }

    /** Картинка с зеркальным полем $b со всех сторон — то же, что GdAdapter::mirrorPad(). */
    private static function mirrorPad(\Imagick $image, int $w, int $h, int $b): \Imagick {
        $pad = new \Imagick();
        $pad->newImage($w + 2 * $b, $h + 2 * $b, new \ImagickPixel('black'), 'png');
        $pad->compositeImage($image, \Imagick::COMPOSITE_COPY, $b, $b);

        if ($b <= 0) return $pad;

        $flipH = clone $image; $flipH->flopImage();
        $flipV = clone $image; $flipV->flipImage();
        $flipB = clone $image; $flipB->flopImage(); $flipB->flipImage();

        $strips = [
            [$flipH, $w - $b, 0,       $b, $h, 0,       $b],
            [$flipH, 0,       0,       $b, $h, $b + $w, $b],
            [$flipV, 0,       $h - $b, $w, $b, $b,      0],
            [$flipV, 0,       0,       $w, $b, $b,      $b + $h],
            [$flipB, $w - $b, $h - $b, $b, $b, 0,       0],
            [$flipB, 0,       $h - $b, $b, $b, $b + $w, 0],
            [$flipB, $w - $b, 0,       $b, $b, 0,       $b + $h],
            [$flipB, 0,       0,       $b, $b, $b + $w, $b + $h],
        ];

        foreach ($strips as [$source, $sx, $sy, $sw, $sh, $dx, $dy]) {
            $part = clone $source;
            $part->cropImage($sw, $sh, $sx, $sy);
            $part->setImagePage(0, 0, 0, 0);
            $pad->compositeImage($part, \Imagick::COMPOSITE_COPY, $dx, $dy);
            $part->destroy();
        }

        $flipH->destroy();
        $flipV->destroy();
        $flipB->destroy();

        return $pad;
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
