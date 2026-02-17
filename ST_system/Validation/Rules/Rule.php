<?php

namespace ST_system\Validation\Rules;

final class Rule {

    private int $priority = 0;
    private bool $bail = false;
    private bool $stopChainWhenPassed = false;
    private callable $callback;
    private array $params = [];
    private ?callable $before = null;
    private ?callable $after = null;

    public function __construct(
        callable $callback,
        array $params = [],
        ?callable $before = null,
        ?callable $after = null
    ) {
        if (isset($params['priority'])) {
            $this->priority = (int) $params['priority'];
            unset($params['priority']);
        }
        if (isset($params['bail'])) {
            $this->bail = (bool) $params['bail'];
            unset($params['bail']);
        }
        if (isset($params['stop_chain_when_passed'])) {
            $this->stopChainWhenPassed = (bool) $params['stop_chain_when_passed'];
            unset($params['stop_chain_when_passed']);
        }

        $this->callback = $callback;
        $this->params   = $params;
        $this->before   = $before;
        $this->after    = $after;
    }

    public function getPriority(): int {
        return $this->priority;
    }

    public function getBail(): bool {
        return $this->bail;
    }

    public function getStopChainWhenPassed(): bool {
        return $this->stopChainWhenPassed;
    }

    public function getParams(): array {
        return $this->params;
    }

    /**
     * Apply before transform to value. Called by Validator before validate().
     */
    public function runBefore($value, array $context = []): mixed {
        if ($this->before === null) {
            return $value;
        }
        return ($this->before)($value, $context);
    }

    /**
     * Run validation callback. Value should already be transformed by runBefore if applicable.
     */
    public function validate($value, array $context = []): bool {
        return (bool) ($this->callback)($value, $this->params, $context);
    }

    /**
     * Apply after transform to value. Called by Validator after validate() passes.
     */
    public function runAfter($value, array $context = []): mixed {
        if ($this->after === null) {
            return $value;
        }
        return ($this->after)($value, $context);
    }

    /**
     * Return a new Rule with merged params (e.g. for "max:50" from template "max").
     */
    public function withParams(array $additionalParams): self {
        $params = array_merge($this->params, $additionalParams);
        return new self($this->callback, $params, $this->before, $this->after);
    }
}
