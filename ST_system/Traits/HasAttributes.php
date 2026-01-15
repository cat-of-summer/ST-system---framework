<?php

namespace ST_system\Traits;

trait HasAttributes {

    // use HasAttributes {
    //     __get as private getAttribute;
    //     __set as private setAttribute;
    // }

    protected array $attributes = [];

    public function __get(string $name) {
        return method_exists($this, $attribute = 'get'.ucfirst($name).'Attribute')
            ? $this->{$attribute}()
            : ($this->attributes[$name] ?? null);
    }

    public function __set(string $name, $value): void {
        if (method_exists($this, $attribute = 'set'.ucfirst($name).'Attribute'))
            $this->{$attribute}($value);
        else
            $this->attributes[$name] = $value;
    }
}