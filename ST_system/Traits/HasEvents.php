<?php

namespace ST_system\Traits;

trait HasEvents {

    private array $listeners = [];

    protected static function getReservedEvents(): array {
        return [];
    }

    final protected function on(string $event, callable $listener): void {
        $this->listeners[$event][] = $listener;
    }

    private function fire(string $event, &...$params): void {
        if (empty($this->listeners[$event])) return;

        foreach ($this->listeners[$event] as $listener)
            call_user_func_array($listener, $params);
    }

    final protected function trigger(string $event, &...$params): void {
        if (in_array($event, static::getReservedEvents(), true))
            throw new \LogicException("Event '{$event}' is reserved and cannot be triggered externally.");

        return $this->fire($event, ...$params);
    }
}
