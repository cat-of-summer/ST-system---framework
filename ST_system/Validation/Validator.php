<?php

namespace ST_system\Validation;

final class Validator {

    /** @var array<string, string|array|Rule> */
    private $rules = [];

    /** @var array<string, string[]> */
    public $errors = [];

    /** @var bool */
    private $isSilent = true;

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

    // ─── Основной метод ─────────────────────────────────────────────

    /**
     * Валидирует и мутирует $data по ссылке.
     * Поля без правил удаляются из $data.
     */
    public function validate(array &$data, ?callable $onPrepare = null): void {
        $this->errors = [];
        $result = [];

        foreach ($this->rules as $field => $ruleSpec) {
            $exists = array_key_exists($field, $data);
            $value  = $exists ? $data[$field] : null;

            // Нормализация правила
            $rule = $this->normalizeRule($ruleSpec);

            $context = [
                '__key'    => $field,
                '__exists' => $exists,
                '__silent' => $this->isSilent,
                '__skip'   => false,
            ];

            // Выполнение правила
            try {
                $nestedErrors = $rule->applyWithErrors($value, $context);

                // skipField (sometimes / excludeIf) — поле не было в данных
                if (!empty($context['__skip'])) {
                    continue;
                }

                if ($nestedErrors !== null) {
                    $this->mergeErrors($field, $nestedErrors, $rule, $value);
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
            $dotKey = $field . '.' . $subField;
            $this->errors[$dotKey] = $this->errors[$dotKey] ?? [];
            if (is_array($subErrors)) {
                array_push($this->errors[$dotKey], ...$subErrors);
            } else {
                $this->errors[$dotKey][] = (string) $subErrors;
            }
        }
    }
}
