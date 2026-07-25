<?php

namespace ST_system\Captcha\Drivers\Image;

interface ImageAdapterInterface {

    public static function isAvailable(): bool;

    public static function render(string $text, array $options): string;
}
