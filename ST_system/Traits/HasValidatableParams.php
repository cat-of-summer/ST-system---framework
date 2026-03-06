<?php

namespace ST_system\Traits;

trait HasValidatableParams {

    protected static array $RULES = [];

    final public static function prepare_params(array $config, &$input, $on_prepare = null) {
        $is_scalar = !is_array($input);
        $values    = $is_scalar ? [0 => $input] : $input;
        $rules     = $is_scalar ? [0 => $config] : $config;
        $result    = [];

        foreach ($rules as $key => $rule_config) {
            if (is_string($rule_config)) {
                $rule_config = static::rule($rule_config);
            }

            $hasValue   = array_key_exists($key, $values);
            $hasDefault = array_key_exists('default', $rule_config) || array_key_exists(0, $rule_config);
            $defaultRaw = $hasDefault ? ($rule_config['default'] ?? $rule_config[0]) : null;
            $default    = is_callable($defaultRaw) ? $defaultRaw($key, $result) : $defaultRaw;

            $rule   = $rule_config['rule']   ?? $rule_config[1] ?? null;
            $before = $rule_config['before'] ?? $rule_config[2] ?? null;
            $after  = $rule_config['after']  ?? $rule_config[3] ?? null;

            $value = $hasValue ? $values[$key] : $default;

            if (is_callable($before)) {
                $value = $before($value, $key, $result);
            }

            if (is_callable($rule) && !$rule($value, $key, $result)) {
                $value = $default;
            }

            if ($value instanceof \Throwable) {
                throw $value;
            }

            if (is_callable($after)) {
                $value = $after($value, $key, $result);
            }

            $result[$key] = $value;
        }

        if ($is_scalar) {
            $input = $result[0] ?? null;
        } else {
            foreach (array_keys($input) as $k) {
                if (!array_key_exists($k, $result)) {
                    $input[$k] = null;
                }
            }

            foreach ($result as $k => $v) {
                $input[$k] = $v;
            }
        }

        if (is_callable($on_prepare)) {
            $maybe = $on_prepare($input);
            if ($maybe !== null) {
                $input = $maybe;
            }
        }

        return $input;
    }

    final public static function register_rule(string $rule, array $config): void {
        if (!isset(static::$RULES[$rule]))
            static::$RULES[$rule] = $config;
    }

    final public static function register_rules_map(array $rules): void {
        array_walk($rules, fn($config, $rule) => static::register_rule($rule, $config));
    }

    final public static function rule(string $rule): array {
        if (!isset(static::$RULES[$rule]))
            return [];

        $rule_config = static::$RULES[$rule];

        $default = $rule_config['default'] ?? $rule_config[0] ?? null;
        $rule = $rule_config['rule'] ?? $rule_config[1] ?? null;
        $before = $rule_config['before'] ?? $rule_config[2] ?? null;
        $after = $rule_config['after'] ?? $rule_config[3] ?? null;

        return [
            $default,
            $rule,
            $before,
            $after,
            'default' => $default,
            'rule' => $rule,
            'before' => $before,
            'after' => $after,
        ];
    }

    final protected function extend_rule(string $ruleName, array $config) {
        if (!isset(static::$RULES[$ruleName]))
            throw new \Exception("Переданное правило '{$ruleName}' не зарегистрировано в ".get_called_class());

        $rule_config = static::rule($ruleName);

        $default = $config['default'] ?? $config[0] ?? $rule_config['default'] ?? null;
        $rule    = $config['rule']    ?? $config[1] ?? $rule_config['rule']    ?? null;
        $before  = $config['before']  ?? $config[2] ?? $rule_config['before']  ?? null;
        $after   = $config['after']   ?? $config[3] ?? $rule_config['after']   ?? null;

        $merged = [
            0 => $default, 'default' => $default,
            1 => $rule,    'rule'    => $rule,
            2 => $before,  'before'  => $before,
            3 => $after,   'after'   => $after,
        ];

        static::$RULES[$ruleName] = $merged;

        return $merged;
    }
}