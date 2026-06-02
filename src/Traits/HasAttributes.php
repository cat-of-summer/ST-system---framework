<?php

namespace ST_system\Traits;

use ST_system\Main;

trait HasAttributes {

    protected array $attributes = [];

    public function __get(string $name) {
        $method = 'get'.Main::studlyCase($name).'Attribute';

        return method_exists($this, $method)
            ? $this->{$method}()
            : ($this->attributes[$name] ?? null);
    }

    public function __set(string $name, $value): void {
        $method = 'set'.Main::studlyCase($name).'Attribute';

        if (method_exists($this, $method))
            $this->{$method}($value);
        else
            $this->attributes[$name] = $value;
    }
}
