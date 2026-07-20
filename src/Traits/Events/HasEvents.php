<?php

namespace ST_system\Traits\Events;

trait HasEvents {

    use EmitsEvents;

    private array $listeners = [];

    public function on(string $event, callable $listener): void {
        $this->listeners[$event][] = $listener;
    }

    protected function fire(string $event, &...$params) {
        return self::emitTo($this->listeners[$event] ?? [], $params);
    }

    public function trigger(string $event, &...$params) {
        self::assertNotReserved($event);

        return $this->fire($event, ...$params);
    }
}
