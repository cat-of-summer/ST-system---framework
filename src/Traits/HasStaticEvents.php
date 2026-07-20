<?php

namespace ST_system\Traits;

/**
 * Статический аналог HasEvents для классов-фасадов без инстанса.
 *
 * HasEvents держит слушателей в состоянии инстанса и доступен через
 * getInstance() — но у таких классов, как View, приватный конструктор с
 * обязательными аргументами (инстанс = один шаблон), поэтому единого инстанса
 * для событий нет. Слушатели здесь живут в статике, на класс.
 *
 * Как и HasEvents, данные из слушателей возвращаются ТОЛЬКО через by-ref
 * параметры: fire() игнорирует возвраты. Идиома «нет слушателей → false»
 * сохранена, чтобы вызывающий мог откатиться на поведение по умолчанию.
 */
trait HasStaticEvents {

    private static array $listeners = [];

    protected static function getReservedEvents(): array {
        return [];
    }

    public static function on(string $event, callable $listener): void {
        self::$listeners[$event][] = $listener;
    }

    protected static function fire(string $event, &...$params) {
        if (empty(self::$listeners[$event])) return false;

        foreach (self::$listeners[$event] as $listener)
            call_user_func_array($listener, $params);
    }

    public static function trigger(string $event, &...$params) {
        if (in_array($event, static::getReservedEvents(), true))
            throw new \LogicException("Event '{$event}' is reserved and cannot be triggered externally.");

        return static::fire($event, ...$params);
    }
}
