<?php

namespace ST_system\Traits\Events;

trait EmitsEvents {

    protected static function getReservedEvents(): array {
        return [];
    }

    private static function emitTo(array $listeners, array $params) {
        if (!$listeners) return false;

        foreach ($listeners as $listener)
            call_user_func_array($listener, $params);
    }

    private static function assertNotReserved(string $event): void {
        if (in_array($event, static::getReservedEvents(), true))
            throw new \LogicException("Event '{$event}' is reserved and cannot be triggered externally.");
    }
}
