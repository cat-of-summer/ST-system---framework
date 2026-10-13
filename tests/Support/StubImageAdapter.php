<?php

namespace ST_system\Tests\Support;

use ST_system\Captcha\Drivers\Image\ImageAdapterInterface;
use ST_system\Captcha\Drivers\Image\PuzzleAdapterInterface;

/**
 * Адаптер картинок для тестов: в php:*-cli нет ни GD, ни Imagick. Ничего не рисует, а запоминает,
 * что его попросили нарисовать, — по этому тест узнаёт задание.
 */
final class StubImageAdapter implements ImageAdapterInterface, PuzzleAdapterInterface {

    public static string $text   = '';
    public static array $puzzle  = [];
    public static string $file   = '';

    public static function isAvailable(): bool {
        return true;
    }

    public static function render(string $text, array $options): string {
        self::$text = $text;

        return "\x89PNG\r\n\x1a\nstub";
    }

    public static function puzzle(string $file, array $options): string {
        self::$file   = $file;
        self::$puzzle = $options;

        return "\xFF\xD8\xFF\xE0stub";
    }
}
