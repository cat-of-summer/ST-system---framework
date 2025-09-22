<?php

namespace ST_system\API\Traits;

use \ST_system\Main;

trait Overridable {
    final public function override_method(string $method, $config = []): self {
        if (!isset($this->methods_map[$method]))
            throw new \Exception("Метод '{$method}' не зарегистрирован в ".get_called_class());

        $old_config = $this->methods_map[$method];
        $this->unregister_method($method);

        $this->register_method($method, is_callable($config)
            ? $config
            : Main::merge($old_config, $config)    
        );

        return $this;
    }

    final protected function override_methods_map(array $methods): self {
        array_walk($methods, fn($config, $method) => $this->override_method($method, $config));

        return $this;
    }

    final public function p_prepare_params(array $config, &$input, $on_prepare = null) {
        return $this->prepare_params($config, $input, $on_prepare);
    }

    final public function p_rule(string $rule) {
        return $this->rule($rule);
    }
}