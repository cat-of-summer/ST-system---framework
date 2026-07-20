<?php

namespace ST_system\Traits\Events;

trait HasStaticEvents {

    use EmitsEvents;

    private static array $listeners = [];

    public static function on(string $event, callable $listener): void {
        self::$listeners[$event][] = $listener;
    }

    protected static function fire(string $event, &...$params) {
        return self::emitTo(self::$listeners[$event] ?? [], $params);
    }

    public static function trigger(string $event, &...$params) {
        self::assertNotReserved($event);

        return static::fire($event, ...$params);
    }
}
