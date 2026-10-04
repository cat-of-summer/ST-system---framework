<?php

namespace ST_system\MCP;

/**
 * Справочник инструментов для человека, собранный из самого сервера: описания живут в
 * инструментах и уходят агенту по протоколу, а рукописная копия разъехалась бы с ними на
 * первой же правке. problems() — проверка полноты описаний для тестов и CLI-команды.
 */
final class ToolsDoc {

    /** Короче — это уже не описание, а подпись. */
    public const MIN_DESCRIPTION = 80;

    /** @var Dispatcher */
    private $dispatcher;
    /** @var array{title:string,note:string} */
    private $options;

    /**
     * @param array{title?:string,note?:string} $options заголовок и вводная строка
     *                                                    (например, откуда файл и где править описания)
     */
    public function __construct(Dispatcher $dispatcher, array $options = []) {
        $this->dispatcher  = $dispatcher;
        $this->options = $options + ['title' => 'MCP — справочник инструментов', 'note' => ''];
    }

    /** @return string[] пустой массив — всё описано */
    public function problems(): array {
        $problems = [];

        if (mb_strlen($this->dispatcher->instructions()) < self::MIN_DESCRIPTION)
            $problems[] = 'instructions сервера пусты или слишком коротки';

        foreach ($this->dispatcher->toolsList()['tools'] as $tool) {
            $name   = $tool['name'];
            $schema = $tool['inputSchema'];

            if (trim($tool['title'] ?? '') === '' || $tool['title'] === $name)
                $problems[] = "{$name}: нет названия для человека";

            if (mb_strlen(trim($tool['description'] ?? '')) < self::MIN_DESCRIPTION)
                $problems[] = "{$name}: описание короче ".self::MIN_DESCRIPTION.' символов';

            if (($schema['type'] ?? '') !== 'object')
                $problems[] = "{$name}: inputSchema должна быть объектом";

            $properties = (array)($schema['properties'] ?? []);

            foreach ($properties as $param => $property)
                if (trim($property['description'] ?? '') === '')
                    $problems[] = "{$name}.{$param}: у параметра нет описания";

            foreach ($schema['required'] ?? [] as $param)
                if (!isset($properties[$param]))
                    $problems[] = "{$name}: обязательный параметр {$param} не описан в properties";
        }

        return $problems;
    }

    public function markdown(): string {
        $lines = ["# {$this->options['title']}", ''];

        if ($this->options['note'] !== '')
            array_push($lines, "> {$this->options['note']}", '');

        $instructions = $this->dispatcher->instructions();

        if ($instructions !== '')
            array_push($lines, '## Инструкции сервера', '', 'Клиент показывает их модели при подключении.', '', '```text', $instructions, '```', '');

        $lines[] = '## Инструменты';
        $lines[] = '';

        foreach ($this->dispatcher->tools() as $name => $tool) {
            $annotations = $tool->annotations();
            $flags = [
                !empty($annotations['readOnlyHint']) ? 'только чтение' : 'меняет состояние',
                $tool->asksHuman() ? 'спрашивает человека' : 'без вопросов человеку',
            ];

            if (!empty($annotations['destructiveHint'])) $flags[] = 'необратимо';

            array_push($lines,
                "### {$name}", '',
                "**{$tool->title()}** · ".implode(' · ', $flags), '',
                $tool->description(), '',
                self::params($tool->inputSchema()), ''
            );
        }

        return implode("\n", $lines);
    }

    private static function params(array $schema): string {
        $properties = (array)($schema['properties'] ?? []);
        if ($properties === []) return '_Без параметров._';

        $required = $schema['required'] ?? [];
        $rows = [
            '| Параметр | Тип | Обязателен | Описание |',
            '|---|---|---|---|',
        ];

        foreach ($properties as $name => $property) {
            $type = isset($property['enum'])
                ? implode(' \| ', array_map(function ($v) { return '`'.(is_string($v) ? $v : json_encode($v)).'`'; }, $property['enum']))
                : implode(' \| ', (array)($property['type'] ?? 'string'));

            $description = $property['description'] ?? '';
            if (array_key_exists('default', $property))
                $description .= ' По умолчанию: `'.json_encode($property['default'], JSON_UNESCAPED_UNICODE).'`.';

            $rows[] = sprintf(
                '| `%s` | %s | %s | %s |',
                $name, $type, in_array($name, $required, true) ? 'да' : 'нет',
                str_replace(['|', "\n"], ['\|', ' '], trim($description))
            );
        }

        return implode("\n", $rows);
    }
}
