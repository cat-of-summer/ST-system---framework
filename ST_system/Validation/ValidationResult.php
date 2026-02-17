<?php

namespace ST_system\Validation;

final class ValidationResult {

    /** @var array<string, mixed> */
    private array $validated;

    /** @var array<string, string[]> */
    private array $errors;

    /**
     * @param array<string, mixed>   $validated
     * @param array<string, string[]> $errors
     */
    public function __construct(array $validated, array $errors) {
        $this->validated = $validated;
        $this->errors    = $errors;
    }

    /** @return array<string, mixed> */
    public function validated(): array {
        return $this->validated;
    }

    /** @return array<string, string[]> */
    public function errors(): array {
        return $this->errors;
    }

    public function fails(): bool {
        return count($this->errors) > 0;
    }

    public function passes(): bool {
        return !$this->fails();
    }
}
