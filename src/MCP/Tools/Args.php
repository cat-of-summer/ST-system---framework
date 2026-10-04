<?php

namespace ST_system\MCP\Tools;

use ST_system\Rule;

/**
 * Проверка аргументов инструмента: inputSchema (JSON Schema — контракт для клиента)
 * компилируется в Rule::object, к нему добавляются правила Rule самого инструмента
 * (Tool::rules — trim, email, замыкания: то, чего в JSON Schema нет).
 *
 * Из JSON Schema понимается то, что встречается в схемах инструментов:
 * - type (строкой или списком, с 'null'), required, default, enum, pattern;
 * - minLength/maxLength, minimum/maximum, minItems/maxItems;
 * - items у массивов, properties у вложенных объектов, additionalProperties.
 *
 * Неизвестные параметры отклоняются, если схема не разрешает их явно
 * (additionalProperties: true) — на любой глубине, в том числе в объектах-элементах массивов
 * (ftp.0.pasword): опечатку в имени модель увидит и исправит, а не получит
 * молча проигнорированный аргумент. Отсутствующий необязательный параметр и null
 * равнозначны: подставляется default, если он есть, иначе ключа нет.
 */
final class Args {

    /**
     * @param mixed $args  arguments из tools/call
     * @param array $rules дополнительные правила Rule по именам параметров (Tool::rules)
     * @throws \InvalidArgumentException с понятным модели текстом
     */
    public static function validate(array $schema, $args, array $rules = []): array {
        if ($args === null) $args = [];
        if (!is_array($args) || ($args !== [] && self::isList($args)))
            throw new \InvalidArgumentException('arguments должен быть объектом.');

        self::rejectUnknown($schema, $args, '');

        $errors = self::object($schema, $rules)->apply($args);

        if ($errors !== [])
            throw new \InvalidArgumentException(implode('; ', $errors));

        return $args;
    }

    /** Схема объекта → правило. Ключи вне properties при additionalProperties: true сохраняются. */
    public static function object(array $schema, array $rules = []): Rule {
        $properties = (array)($schema['properties'] ?? []);
        $required   = (array)($schema['required'] ?? []);
        $fields     = [];

        foreach ($properties as $name => $property)
            $fields[$name] = self::field((array)$property, in_array($name, $required, true));

        foreach ($rules as $name => $spec)
            $fields[$name] = array_merge($fields[$name] ?? ['sometimes'], is_array($spec) ? $spec : [$spec]);

        $rule = Rule::object($fields);
        $keep = ($schema['additionalProperties'] ?? false) !== false;

        return Rule::create(static function (&$v) use ($rule, $fields, $keep) {
            $extra  = $keep && is_array($v) ? array_diff_key($v, $fields) : [];
            $errors = $rule->apply($v);

            if ($extra !== []) $v += $extra;

            return $errors;
        })->order(600);
    }

    /** Правила одного параметра: обязательность, тип, ограничения. */
    private static function field(array $property, bool $required): array {
        if ($required)
            $spec = ['required'];
        elseif (array_key_exists('default', $property))
            $spec = [Rule::default($property['default'])];
        else
            $spec = [self::optional()];

        $types = array_values(array_diff((array)($property['type'] ?? []), ['null']));

        if (count($types) === 1)
            $spec[] = self::type($types[0], $property);
        elseif (count($types) > 1)
            $spec[] = Rule::anyOf(...array_map(function ($type) use ($property) { return [self::type($type, $property)]; }, $types));

        foreach (['minLength' => 'min', 'minimum' => 'min', 'minItems' => 'min', 'maxLength' => 'max', 'maximum' => 'max', 'maxItems' => 'max'] as $keyword => $alias)
            if (isset($property[$keyword]))
                $spec[] = $alias.':'.$property[$keyword];

        if (isset($property['enum']))
            $spec[] = self::enum((array)$property['enum']);

        if (isset($property['pattern']))
            $spec[] = Rule::regex('/'.str_replace('/', '\/', $property['pattern']).'/u');

        return $spec;
    }

    /** Нет значения или null — остальные правила не выполняются, ошибки нет (как sometimes). */
    private static function optional(): Rule {
        return Rule::create(function (&$v): bool { return !($v === null || Rule::isSentinel($v)); })
            ->order(0)->skip()->seesSentinel();
    }

    /**
     * Тип JSON Schema. Строки, пришедшие вместо целых, чисел и булевых, приводятся к типу:
     * модели нередко присылают "20" вместо 20.
     */
    private static function type(string $type, array $property): Rule {
        switch ($type) {
            case 'string':
                return self::check(function (&$v): bool { return is_string($v); }, 'должно быть строкой');

            case 'integer':
                return self::check(function (&$v): bool {
                    if (is_string($v) && preg_match('/^-?\d+$/', $v)) $v = (int)$v;
                    if (is_float($v) && floor($v) === $v && abs($v) < PHP_INT_MAX) $v = (int)$v;
                    return is_int($v);
                }, 'должно быть целым числом');

            case 'number':
                return self::check(function (&$v): bool {
                    if (is_string($v) && is_numeric($v)) $v = $v + 0;
                    return is_int($v) || is_float($v);
                }, 'должно быть числом');

            case 'boolean':
                return self::check(function (&$v): bool {
                    if ($v === 'true' || $v === 'false') $v = $v === 'true';
                    return is_bool($v);
                }, 'должно быть true или false');

            case 'array':
                $list  = self::check(function (&$v): bool { return is_array($v) && self::isList($v); }, 'должно быть массивом');
                $items = isset($property['items']) && is_array($property['items']) ? self::items($property['items']) : null;

                return $items === null ? $list : Rule::create([$list, $items]);

            case 'object':
                $assoc = self::check(function (&$v): bool { return is_array($v) && ($v === [] || !self::isList($v)); }, 'должно быть объектом');

                return isset($property['properties']) ? Rule::create([$assoc, self::object($property)]) : $assoc;
        }

        return Rule::create(function (): bool { return true; });
    }

    /** Каждый элемент массива — по схеме items; ошибки с номером элемента. */
    private static function items(array $schema): Rule {
        $item = Rule::create(self::field($schema, true));

        return Rule::create(static function (&$v) use ($item): array {
            $errors = [];

            foreach ($v as $i => &$element)
                foreach ($item->apply($element) as $error)
                    $errors[] = "{$i}.{$error}";

            unset($element);

            return $errors;
        })->order(600);
    }

    private static function enum(array $values): Rule {
        $allowed = implode(', ', array_map(function ($v) { return is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE); }, $values));

        // Строгое сравнение: Rule::in сравнивает нестрого, а в PHP 7.4 0 == 'auto'.
        return Rule::create(function (&$v) use ($values): bool { return in_array($v, $values, true); })
            ->order(700)
            ->handleError(function () use ($allowed): string { return "допустимо {$allowed}"; });
    }

    private static function check(\Closure $test, string $message): Rule {
        return Rule::create($test)->order(500)->handleError(function () use ($message): string { return $message; });
    }

    private static function rejectUnknown(array $schema, array $args, string $path): void {
        $properties = (array)($schema['properties'] ?? []);

        if (($schema['additionalProperties'] ?? false) === false) {
            $unknown = array_diff(array_keys($args), array_keys($properties));

            if ($unknown !== [])
                throw new \InvalidArgumentException('Неизвестные параметры: '.implode(', ', array_map(function ($n) use ($path) { return $path.$n; }, $unknown)).'.');
        }

        foreach ($properties as $name => $property) {
            if (!isset($args[$name]) || !is_array($args[$name])) continue;

            if (isset($property['properties']) && !self::isList($args[$name]))
                self::rejectUnknown((array)$property, $args[$name], "{$path}{$name}.");

            // Элементы-объекты массива: опечатка в поле элемента — тоже ошибка, а не тихая потеря.
            if (isset($property['items']['properties']) && self::isList($args[$name]))
                foreach ($args[$name] as $i => $item)
                    if (is_array($item) && !self::isList($item))
                        self::rejectUnknown((array)$property['items'], $item, "{$path}{$name}.{$i}.");
        }
    }

    private static function isList(array $array): bool {
        return $array === [] || array_keys($array) === range(0, count($array) - 1);
    }
}
