<?php

namespace ST_system\Traits;

use ST_system\Main;

trait HasAttributes {

    protected array $attributes = [];
    protected array $attribute_cache = [];
    private ?array $attribute_map = null;


    protected function attributeMap(): array { return []; }

    protected function purgeAttributes(): void {
        $this->attribute_cache = [];
    }

    public function __get(string $name) {
        $method = 'get'.Main::studlyCase($name).'Attribute';

        if (method_exists($this, $method))
            return $this->{$method}();

        $this->attribute_map ??= $this->attributeMap();

        if (!isset($this->attribute_map[$name]))
            return $this->attributes[$name] ?? null;

        if (array_key_exists($name, $this->attribute_cache))
            return $this->attribute_cache[$name];

        $definition = $this->attribute_map[$name];
        [$resolver, $cache] = (is_array($definition) ? $definition : [$definition]) + [1 => false];


        if (!is_string($resolver))
            $value = call_user_func($resolver);
        elseif (method_exists($this, $resolver) || method_exists($this, '__call'))
            $value = $this->{$resolver}();
        else
            $value = $resolver();

        if ($cache) $this->attribute_cache[$name] = $value;

        return $value;
    }

    public function __set(string $name, $value): void {
        $method = 'set'.Main::studlyCase($name).'Attribute';

        unset($this->attribute_cache[$name]);

        if (method_exists($this, $method))
            $this->{$method}($value);
        else
            $this->attributes[$name] = $value;
    }

    public function __isset(string $name): bool {
        $this->attribute_map ??= $this->attributeMap();

        return method_exists($this, 'get'.Main::studlyCase($name).'Attribute')
            || isset($this->attribute_map[$name])
            || isset($this->attributes[$name]);
    }

    public function __unset(string $name): void {
        unset($this->attributes[$name], $this->attribute_cache[$name]);
    }
}
