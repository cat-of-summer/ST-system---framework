<?php

namespace ST_system\Validation;

use ST_system\Validation\Rules\Rule;

final class Validator {

    /** @var array<string, Rule> */
    private array $rulesMap = [];

    public function __construct() {
        $this->rulesMap = $this->defaultRules();
    }

    /**
     * @return array<string, Rule>
     */
    final public function defaultRules(): array {
        $priorityPresence = 100;
        $priorityType     = 50;
        $priorityConstraint = 0;

        return [
            'required' => new Rule(
                function ($value): bool {
                    return $value !== null && $value !== '';
                },
                ['priority' => $priorityPresence, 'bail' => true]
            ),
            'nullable' => new Rule(
                function ($value): bool {
                    return $value === null || $value === '';
                },
                ['priority' => $priorityPresence, 'stop_chain_when_passed' => true]
            ),
            'string' => new Rule(
                fn($value): bool => is_string($value),
                ['priority' => $priorityType]
            ),
            'int' => new Rule(
                fn($value): bool => is_int($value),
                ['priority' => $priorityType],
                null,
                fn($v): int => (int) $v
            ),
            'float' => new Rule(
                fn($value): bool => is_float($value) || is_numeric($value),
                ['priority' => $priorityType],
                null,
                fn($v): float => (float) $v
            ),
            'bool' => new Rule(
                fn($value): bool => is_bool($value) || in_array($value, ['0', '1', 0, 1, 'true', 'false'], true),
                ['priority' => $priorityType],
                null,
                fn($v): bool => (bool) (is_string($v) ? filter_var($v, FILTER_VALIDATE_BOOLEAN) : $v)
            ),
            'max' => new Rule(
                function ($value, array $params): bool {
                    $limit = $params[0] ?? $params['max'] ?? null;
                    if ($limit === null) {
                        return true;
                    }
                    $limit = (int) $limit;
                    if (is_string($value)) {
                        return mb_strlen($value) <= $limit;
                    }
                    if (is_array($value)) {
                        return count($value) <= $limit;
                    }
                    return is_numeric($value) && (float) $value <= $limit;
                },
                ['priority' => $priorityConstraint]
            ),
            'min' => new Rule(
                function ($value, array $params): bool {
                    $limit = $params[0] ?? $params['min'] ?? null;
                    if ($limit === null) {
                        return true;
                    }
                    $limit = (int) $limit;
                    if (is_string($value)) {
                        return mb_strlen($value) >= $limit;
                    }
                    if (is_array($value)) {
                        return count($value) >= $limit;
                    }
                    return is_numeric($value) && (float) $value >= $limit;
                },
                ['priority' => $priorityConstraint]
            ),
        ];
    }

    /**
     * Parse a rule string like "required|string|max:50" into an array of Rule instances.
     *
     * @param array<string, Rule>|null $rulesMap defaults to $this->defaultRules()
     * @return Rule[]
     */
    public function parseRuleString(string $ruleString, ?array $rulesMap = null): array {
        $rulesMap = $rulesMap ?? $this->defaultRules();
        $segments = array_map('trim', explode('|', trim($ruleString)));
        $result   = [];

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            $params = [];
            if (strpos($segment, ':') !== false) {
                [$name, $paramStr] = explode(':', $segment, 2);
                $name   = trim($name);
                $params = array_map('trim', explode(',', $paramStr));
                if (count($params) === 1 && $name !== 'in') {
                    $params = [$params[0]];
                }
            } else {
                $name = $segment;
            }

            if (!isset($rulesMap[$name])) {
                continue;
            }

            $template = $rulesMap[$name];
            if (!$template instanceof Rule) {
                continue;
            }

            if (count($params) === 0) {
                $result[] = $template;
                continue;
            }

            $merged = [];
            foreach ($params as $i => $p) {
                $merged[$i] = $p;
            }
            if ($name === 'max') {
                $merged['max'] = $params[0];
            } elseif ($name === 'min') {
                $merged['min'] = $params[0];
            }
            $result[] = $template->withParams($merged);
        }

        return $result;
    }

    /**
     * @param array<string, string|Rule[]> $rules field => rule string or array of Rule
     * @param array<string, mixed>         $defaults field => default value
     */
    public function validate(array $data, array $rules, array $defaults = []): ValidationResult {
        $validated = [];
        $errors    = [];

        foreach ($rules as $field => $ruleSpec) {
            $value = array_key_exists($field, $data) ? $data[$field] : ($defaults[$field] ?? null);

            $ruleList = is_string($ruleSpec)
                ? $this->parseRuleString($ruleSpec)
                : $ruleSpec;

            if (count($ruleList) === 0) {
                $validated[$field] = $value;
                continue;
            }

            $ruleList = $this->sortRulesByPriority($ruleList);
            $context  = ['key' => $field, 'result' => &$validated];
            $fieldFailed = false;

            foreach ($ruleList as $rule) {
                $value = $rule->runBefore($value, $context);

                if (!$rule->validate($value, $context)) {
                    if (!isset($errors[$field])) {
                        $errors[$field] = [];
                    }
                    $errors[$field][] = 'Validation failed for ' . $field;
                    $fieldFailed = true;
                    if ($rule->getBail()) {
                        break;
                    }
                    continue;
                }

                if ($rule->getStopChainWhenPassed()) {
                    $validated[$field] = $value;
                    continue 2;
                }

                $value = $rule->runAfter($value, $context);
            }

            if (!$fieldFailed) {
                $validated[$field] = $value;
            }
        }

        return new ValidationResult($validated, $errors);
    }

    /**
     * @param Rule[] $ruleList
     * @return Rule[]
     */
    private function sortRulesByPriority(array $ruleList): array {
        usort($ruleList, fn(Rule $a, Rule $b): int => $b->getPriority() <=> $a->getPriority());
        return $ruleList;
    }
}
