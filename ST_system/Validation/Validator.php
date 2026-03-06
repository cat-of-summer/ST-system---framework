<?php

namespace ST_system\Validation;

final class Validator {

    /** @var array<string, string|array|Rule> */
    private $rules = [];

    /** @var array<string, string[]> */
    public $errors = [];

    /** @var bool */
    private $isSilent = true;

    /** @var bool */
    private $isSafe = false;

    private function __construct(array $rules) {
        $this->rules = $rules;
    }

    // ─── Фабрики ────────────────────────────────────────────────────

    public static function create(array $rules): self {
        return new self($rules);
    }

    // ─── Режимы ─────────────────────────────────────────────────────

    /** @return self */
    public function silent(): self {
        $this->isSilent = true;
        return $this;
    }

    /** @return self */
    public function loud(): self {
        $this->isSilent = false;
        return $this;
    }

    /** @return self */
    public function safe(): self {
        $this->isSafe = true;
        return $this;
    }

    /** @return self */
    public function unsafe(): self {
        $this->isSafe = false;
        return $this;
    }

    // ─── Основной метод ─────────────────────────────────────────────

    /**
     * Валидирует и мутирует $data по ссылке.
     * Flat-поля без правил удаляются. Dot-path/wildcard затрагивают только конкретные листья.
     */
    public function validate(array &$data, ?callable $onPrepare = null): void {
        $this->errors = [];
        $result       = [];
        $dotTopKeys   = [];

        foreach ($this->rules as $field => $ruleSpec) {
            $rule     = $this->normalizeRule($ruleSpec);
            $isNested = strpos($field, '.') !== false || strpos($field, '*') !== false;

            if (!$isNested) {
                $this->validateFlat($data, $field, $rule, $result);
            } else {
                $segments = $this->parsePath($field);
                $dotTopKeys[$segments[0]] = true;

                if (in_array('*', $segments, true)) {
                    $paths = $this->expandWildcards($data, $segments);
                } else {
                    $paths = [$segments];
                }

                foreach ($paths as $path) {
                    $this->validateOnePath($data, $path, $rule);
                }
            }
        }

        // Топ-ключи dot-path/wildcard правил переносим из уже мутированного $data
        foreach ($dotTopKeys as $key => $_) {
            if (array_key_exists($key, $data)) {
                $result[$key] = $data[$key];
            }
        }

        $data = $result;

        if ($onPrepare !== null) {
            $onPrepare($data);
        }
    }

    // ─── Статический шорткат ────────────────────────────────────────

    /**
     * Быстрая валидация без создания экземпляра вручную.
     */
    public static function check(array $rules, array &$data, ?callable $onPrepare = null): void {
        $v = new self($rules);
        $v->validate($data, $onPrepare);
    }

    // ─── Приватные хелперы ───────────────────────────────────────────

    /**
     * @param string|array|Rule $spec
     * @return Rule
     */
    private function normalizeRule($spec): Rule {
        if ($spec instanceof Rule) {
            return $spec;
        }

        if (is_string($spec)) {
            return Rule::create($spec);
        }

        // Массив: ['required', 'string', 'max:50'] или ['required', Rule::forEach(...)]
        if (is_array($spec)) {
            return $this->normalizeArrayRule($spec);
        }

        return Rule::create(fn() => true);
    }

    private function normalizeArrayRule(array $spec): Rule {
        // Если массив содержит ключ 'rule'/'default'/'before'/'after' — старый формат
        if (array_key_exists('rule', $spec) || array_key_exists('default', $spec)
            || array_key_exists('before', $spec) || array_key_exists('after', $spec)) {
            return Rule::create($spec);
        }

        // Массив строк и Rule: ['required', 'string', Rule::forEach(...)]
        $stringParts = [];
        $extraRules  = [];

        foreach ($spec as $item) {
            if (is_string($item)) {
                $stringParts[] = $item;
            } elseif ($item instanceof Rule) {
                $extraRules[] = $item;
            }
        }

        if (count($stringParts) > 0 && count($extraRules) === 0) {
            return Rule::create(implode('|', $stringParts));
        }

        // Смешанный массив — собираем составной Rule
        $combined = [];
        if (count($stringParts) > 0) {
            $parsed = Rule::create(implode('|', $stringParts));
            $combined[] = $parsed;
        }
        foreach ($extraRules as $er) {
            $combined[] = $er;
        }

        // Обернуть в один составной Rule — используем create со строкой-заглушкой
        // и добавляем в chain...  проще собрать вручную через fromString + merge
        if (count($combined) === 1) return $combined[0];

        // Нужен механизм объединения — создадим Rule::create для объединения
        return $this->mergeRules($combined);
    }

    private function mergeRules(array $rules): Rule {
        $captured = $rules;
        $r = Rule::create(function(&$value, array $params, array $context) use ($captured): bool {
            foreach ($captured as $rule) {
                if (!$rule->apply($value, $context)) {
                    return false;
                }
            }
            return true;
        });
        return $r;
    }

    /**
     * @param mixed $value
     */
    private function mergeErrors(string $field, array $nestedErrors, Rule $rule, $value): void {
        // __self маркер — простая ошибка валидации
        if (isset($nestedErrors['__self'])) {
            $this->errors[$field] = $this->errors[$field] ?? [];
            $msg = $rule->getErrorMessage($value) ?: "Validation failed for {$field}";
            $this->errors[$field][] = $msg;
            return;
        }

        // Вложенные ошибки (object / forEach) — dot-нотация
        foreach ($nestedErrors as $subField => $subErrors) {
            if ($subField === '__partial') continue;
            $dotKey = $field . '.' . $subField;
            $this->errors[$dotKey] = $this->errors[$dotKey] ?? [];
            if (is_array($subErrors)) {
                array_push($this->errors[$dotKey], ...$subErrors);
            } else {
                $this->errors[$dotKey][] = (string) $subErrors;
            }
        }
    }

    // ─── Выполнение правила ───────────────────────────────────────────

    private function validateFlat(array &$data, string $field, Rule $rule, array &$result): void {
        $exists = array_key_exists($field, $data);
        $value  = $exists ? $data[$field] : null;

        $context = [
            '__exists' => $exists,
            '__silent' => $this->isSilent,
            '__safe'   => $this->isSafe,
            '__skip'   => false,
        ];

        try {
            $nestedErrors = $rule->applyWithErrors($value, $context);

            if (!empty($context['__skip'])) {
                return;
            }

            if ($nestedErrors !== null) {
                $this->mergeErrors($field, $nestedErrors, $rule, $value);
                // __partial: forEach нашёл ошибки в части элементов, но массив жив
                if (isset($nestedErrors['__partial'])) {
                    $result[$field] = $value;
                }
            } else {
                $result[$field] = $value;
            }
        } catch (\Throwable $e) {
            if (!$this->isSilent) {
                throw $e;
            }
            $this->errors[$field] = $this->errors[$field] ?? [];
            $this->errors[$field][] = $e->getMessage();
        }
    }

    private function validateOnePath(array &$data, array $path, Rule $rule): void {
        [$value, $exists] = $this->getByPath($data, $path);

        $context = [
            '__exists' => $exists,
            '__silent' => $this->isSilent,
            '__safe'   => $this->isSafe,
            '__skip'   => false,
        ];

        $field = implode('.', $path);

        try {
            $nestedErrors = $rule->applyWithErrors($value, $context);

            if (!empty($context['__skip'])) {
                return;
            }

            if ($nestedErrors !== null) {
                $this->mergeErrors($field, $nestedErrors, $rule, $value);
                if (isset($nestedErrors['__partial'])) {
                    // safe-режим forEach: массив отфильтрован, записываем обратно
                    $this->setByPath($data, $path, $value);
                } else {
                    // лист удаляется, сиблинги выживают
                    $this->unsetByPath($data, $path);
                }
            } else {
                $this->setByPath($data, $path, $value);
            }
        } catch (\Throwable $e) {
            if (!$this->isSilent) {
                throw $e;
            }
            $this->errors[$field] = $this->errors[$field] ?? [];
            $this->errors[$field][] = $e->getMessage();
        }
    }

    // ─── Path хелперы ────────────────────────────────────────────────

    private function parsePath(string $field): array {
        return explode('.', $field);
    }

    /** @return array */
    private function getByPath(array $data, array $path): array {
        $node = $data;
        foreach ($path as $seg) {
            if (!is_array($node) || !array_key_exists($seg, $node)) {
                return [null, false];
            }
            $node = $node[$seg];
        }
        return [$node, true];
    }

    /** @param mixed $value */
    private function setByPath(array &$data, array $path, $value): void {
        $node = &$data;
        $last = array_pop($path);
        foreach ($path as $seg) {
            if (!isset($node[$seg]) || !is_array($node[$seg])) {
                $node[$seg] = [];
            }
            $node = &$node[$seg];
        }
        $node[$last] = $value;
    }

    private function unsetByPath(array &$data, array $path): void {
        $node = &$data;
        $last = array_pop($path);
        foreach ($path as $seg) {
            if (!is_array($node) || !array_key_exists($seg, $node)) {
                return;
            }
            $node = &$node[$seg];
        }
        unset($node[$last]);
    }

    /** @return array[] */
    private function expandWildcards(array $data, array $segments): array {
        $current = [[]];
        foreach ($segments as $seg) {
            $next = [];
            foreach ($current as $prefix) {
                if ($seg !== '*') {
                    $next[] = array_merge($prefix, [$seg]);
                } else {
                    [$node, $nodeExists] = $this->getByPath($data, $prefix);
                    if (!$nodeExists || !is_array($node)) continue;
                    foreach (array_keys($node) as $key) {
                        $next[] = array_merge($prefix, [(string)$key]);
                    }
                }
            }
            $current = $next;
        }
        return $current;
    }
}
