<?php

namespace ST_system\Tests\Support;

use ST_system\HTTP\Response;

/** Ответ фреймворка без отправки: у Response нет геттеров, поля читаются отражением. */
final class ResponseProbe {

    public static function status(Response $response): int {
        return (int)self::field($response, 'status');
    }

    public static function header(Response $response, string $name): ?string {
        foreach ((array)self::field($response, 'headers') as $key => $value)
            if (strcasecmp($key, $name) === 0) return $value;

        return null;
    }

    public static function body(Response $response): string {
        return (string)self::field($response, 'content');
    }

    public static function json(Response $response): ?array {
        $data = json_decode(self::body($response), true);
        return is_array($data) ? $data : null;
    }

    /** Колбэк потокового ответа (Response::stream) или null. */
    public static function stream(Response $response): ?callable {
        $callback = self::field($response, 'stream_callback');
        return is_callable($callback) ? $callback : null;
    }

    private static function field(Response $response, string $name) {
        $property = new \ReflectionProperty(Response::class, $name);
        $property->setAccessible(true);

        return $property->getValue($response);
    }
}
