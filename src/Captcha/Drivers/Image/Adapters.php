<?php

namespace ST_system\Captcha\Drivers\Image;

use ST_system\Storage\Mimes\ImageMime;

/** Выбор адаптера рисования для капч с картинкой: `gd`, `imagick`, `auto` или имя своего класса. */
final class Adapters {

    private const BUILTIN = [
        'gd'      => GdAdapter::class,
        'imagick' => ImagickAdapter::class,
    ];

    /**
     * Доступный адаптер, реализующий $interface, или null. `auto` — тот, что выбран для ImageMime,
     * затем второй встроенный. Имя класса — как есть, если он реализует $interface и доступен.
     */
    public static function resolve(string $requested, string $interface): ?string {
        $key = strtolower(trim($requested));

        if ($key === '' || $key === 'auto') {
            $order = ImageMime::getImageDriver() === 'gd' ? ['gd', 'imagick'] : ['imagick', 'gd'];

            foreach ($order as $name)
                if (self::usable(self::BUILTIN[$name], $interface)) return self::BUILTIN[$name];

            return null;
        }

        $class = self::BUILTIN[$key] ?? ltrim(trim($requested), '\\');

        return self::usable($class, $interface) ? $class : null;
    }

    private static function usable(string $class, string $interface): bool {
        return class_exists($class) && is_subclass_of($class, $interface) && $class::isAvailable();
    }
}
