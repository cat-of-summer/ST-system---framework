<?php

namespace ST_system\MCP\Elicitation;

use ST_system\MCP\Dispatcher;

/**
 * Схемы форм для elicitation: плоский объект из примитивных полей.
 *
 * Подписи вариантов в 2025-11-25 задаются через oneOf [{const, title}]; клиенты 2025-06-18
 * знают только enum + enumNames. Форма собирается под версию, согласованную в initialize.
 */
final class Forms {

    /** @var string */
    private $protocol;

    public function __construct(string $protocol = Dispatcher::LATEST) {
        $this->protocol = $protocol;
    }

    /** @param array<string,string> $options значение => подпись */
    public function choice(string $field, string $title, array $options, string $description = ''): array {
        $property = ['type' => 'string', 'title' => $title];

        if ($description !== '') $property['description'] = $description;

        if (strcmp($this->protocol, '2025-11-25') >= 0) {
            $property['oneOf'] = array_map(
                function ($value, $label) { return ['const' => (string)$value, 'title' => $label]; },
                array_keys($options), $options
            );
        } else {
            $property['enum']      = array_map('strval', array_keys($options));
            $property['enumNames'] = array_values($options);
        }

        return self::object([$field => $property], [$field]);
    }

    /**
     * Вопрос «да или нет» полем boolean. Строку с вариантами часть клиентов показывает
     * полем ввода, а boolean — кнопками Да/Нет.
     */
    public function confirm(string $field, string $title, string $description = ''): array {
        $property = ['type' => 'boolean', 'title' => $title];

        if ($description !== '') $property['description'] = $description;

        return self::object([$field => $property], [$field]);
    }

    public function text(string $field, string $title, string $description = '', ?string $default = null, bool $required = true): array {
        $property = ['type' => 'string', 'title' => $title];

        if ($description !== '') $property['description'] = $description;
        if ($default !== null)   $property['default'] = $default;

        return self::object([$field => $property], $required ? [$field] : []);
    }

    /**
     * Ответ человека на выбор из вариантов. Клиент без кнопок пришлёт не значение, а то, что
     * человек напечатал: «Да», «2», «агент», «English». Совпадение ищется по значению, по
     * номеру варианта, по подписи и по синонимам (вхождение корня в ответ).
     *
     * @param mixed                  $raw
     * @param array<string,string>   $options значение => подпись
     * @param array<string,string[]> $aliases значение => корни слов
     * @return ?string значение варианта или null, если ответ не распознан
     */
    public static function match($raw, array $options, array $aliases = []): ?string {
        if (is_bool($raw)) $raw = $raw ? 'true' : 'false';
        if (!is_scalar($raw)) return null;

        $answer = mb_strtolower(trim((string)$raw, " \t\n\r\0\x0B.,!?;:«»\"'"));
        if ($answer === '') return null;

        $values = array_map('strval', array_keys($options));

        foreach ($values as $value)
            if (mb_strtolower($value) === $answer) return $value;

        if (ctype_digit($answer) && isset($values[(int)$answer - 1]))
            return $values[(int)$answer - 1];

        foreach ($options as $value => $label)
            if (mb_strtolower($label) === $answer) return (string)$value;

        foreach ($aliases as $value => $roots)
            foreach ($roots as $root)
                if (self::hasWord($answer, mb_strtolower($root))) return (string)$value;

        return null;
    }

    /** Корень — с начала слова: «нет» не должен найтись в «интернет». */
    private static function hasWord(string $answer, string $root): bool {
        if (!preg_match('/[\p{L}\p{N}]/u', $root)) return $answer === $root;

        return (bool)preg_match('/(^|[^\p{L}\p{N}])'.preg_quote($root, '/').'/u', $answer);
    }

    private static function object(array $properties, array $required): array {
        return ['type' => 'object', 'properties' => $properties, 'required' => $required];
    }
}
