<?php

namespace ST_system\MCP\Tools;

use ST_system\MCP\Context;

/**
 * Цепочка описания инструмента из замыкания:
 *
 *     Server::tool('tree', fn (array $args, Context $ctx) => Result::ok('…'))
 *         ->title('Дерево')
 *         ->description('…')
 *         ->input(['depth' => ['type' => 'integer', 'description' => '…', 'required' => true]])
 *         ->readOnly();
 */
final class ToolDefinition {

    /** @var ClosureTool */
    private $tool;

    public function __construct(ClosureTool $tool) {
        $this->tool = $tool;
    }

    public function tool(): ClosureTool {
        return $this->tool;
    }

    public function title(string $title): self {
        $this->tool->define('title', $title);
        return $this;
    }

    /** @param string|string[] $description строки списка склеиваются переводом строки */
    public function description($description): self {
        $this->tool->define('description', is_array($description) ? implode("\n", $description) : (string)$description);
        return $this;
    }

    /**
     * Свойства параметров. 'required' => true у свойства — сокращение: оно уйдёт в список
     * required схемы. Лишние параметры отклоняются (additionalProperties: false).
     */
    public function input(array $properties): self {
        $required = [];

        foreach ($properties as $name => &$property) {
            if (($property['required'] ?? null) === true)
                $required[] = $name;

            unset($property['required']);
        }
        unset($property);

        // confirms(), вызванный раньше input(), не должен потеряться при замене схемы.
        $confirm = $this->tool->inputSchema()['properties']['confirm'] ?? null;
        if ($confirm !== null && !isset($properties['confirm']))
            $properties['confirm'] = $confirm;

        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) $schema['required'] = $required;
        $schema['additionalProperties'] = false;

        return $this->schema($schema);
    }

    /** Полная JSON Schema параметров вместо input(). */
    public function schema(array $schema): self {
        $this->tool->define('input', $schema);
        return $this;
    }

    /** Дополнительные правила Rule по именам параметров (Tool::rules). */
    public function rules(array $rules): self {
        $this->tool->define('rules', $rules);
        return $this;
    }

    public function output(array $schema): self {
        $this->tool->define('output', $schema);
        return $this;
    }

    public function annotations(array $annotations): self {
        $this->tool->define('annotations', array_merge($this->tool->annotations(), $annotations));
        return $this;
    }

    public function readOnly(bool $value = true): self {
        return $this->annotations(['readOnlyHint' => $value]);
    }

    public function destructive(bool $value = true): self {
        return $this->annotations(['destructiveHint' => $value]);
    }

    public function idempotent(bool $value = true): self {
        return $this->annotations(['idempotentHint' => $value]);
    }

    public function openWorld(bool $value = true): self {
        return $this->annotations(['openWorldHint' => $value]);
    }

    public function streams(bool $value = true): self {
        $this->tool->define('streams', $value);
        return $this;
    }

    public function asksHuman(bool $value = true): self {
        $this->tool->define('asks_human', $value);
        return $this;
    }

    /**
     * Операция требует подтверждения человека (Context::confirm): в схему добавляется
     * параметр confirm, ответ идёт потоком, инструмент помечается как спрашивающий человека.
     */
    public function confirms(): self {
        $schema = $this->tool->inputSchema();
        $schema['properties']['confirm'] = Context::confirmProperty();

        $this->tool->define('input', $schema);
        $this->tool->define('streams', true);
        $this->tool->define('asks_human', true);

        return $this;
    }
}
