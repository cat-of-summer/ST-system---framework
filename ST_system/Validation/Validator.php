<?php

namespace ST_system\Validation;

use ST_system\Validation\Rule;

final class Validator {
    final public function defaultRules(): array {
        return [
            'string' => new Rule(fn($value) => is_string($value)),
            'int' => new Rule(),
            'float' => new Rule(),
            'bool' => new Rule(),
            'required' => new Rule(),
            'max' => new Rule(),
        ];
    }

}