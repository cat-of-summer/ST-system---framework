<?php

namespace ST_system\Validation;

final class Rule {
    private int $priority = 0;
    private callable $callback;
    private array $params = [];

    public function __construct(callable $callback, array $params = []) {
        if (isset($params['priority'])) {
            $this->priority = (int)$params['priority'];
            unset($params['priority']);
        }

        $this->callback = $callback;
        $this->params = $params;
    }

    final public function validate($value): bool {
        return (bool)($this->callback)($value, $this->params);
    }

    public function create(...$args): static { return new static(...$args); }

    public static function in(): static {

    }

    public static function notIn(): static {

    }

    public static function requiredIf(): static {

    }

    public static function prohibitedIf(): static {

    }

    public static function excludeIf(): static {

    }

    public static function forEach(): static {

    }

    public static function when(): static {

    }


}