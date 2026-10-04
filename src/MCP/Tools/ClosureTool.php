<?php

namespace ST_system\MCP\Tools;

use ST_system\MCP\Context;
use ST_system\MCP\Result;

/**
 * Инструмент из замыкания: Server::tool('name', fn (array $args, Context $ctx) => Result::ok(…)).
 * Описание задаётся цепочкой ToolDefinition, которую возвращает Server::tool().
 */
final class ClosureTool extends Tool {

    /** @var string */
    private $name;
    /** @var callable */
    private $handler;
    /** @var array{title:?string,description:string,input:array,rules:array,output:?array,annotations:array,asks_human:bool,streams:bool} */
    private $spec = [
        'title'       => null,
        'description' => '',
        'input'       => ['type' => 'object', 'properties' => []],
        'rules'       => [],
        'output'      => null,
        'annotations' => [],
        'asks_human'  => false,
        'streams'     => false,
    ];

    public function __construct(string $name, callable $handler) {
        $this->name    = $name;
        $this->handler = $handler;
    }

    /**
     * Меняет описание инструмента; вызывается из ToolDefinition.
     *
     * @internal
     * @param mixed $value
     */
    public function define(string $key, $value): void {
        if (!array_key_exists($key, $this->spec))
            throw new \InvalidArgumentException("Неизвестное поле описания инструмента: {$key}");

        $this->spec[$key] = $value;
    }

    public function name(): string {
        return $this->name;
    }

    public function title(): string {
        return $this->spec['title'] ?? $this->name;
    }

    public function description(): string {
        return $this->spec['description'];
    }

    public function inputSchema(): array {
        return $this->spec['input'];
    }

    public function rules(): array {
        return $this->spec['rules'];
    }

    public function outputSchema(): ?array {
        return $this->spec['output'];
    }

    public function annotations(): array {
        return $this->spec['annotations'];
    }

    public function asksHuman(): bool {
        return $this->spec['asks_human'];
    }

    public function streams(): bool {
        return $this->spec['streams'];
    }

    public function call(array $args, Context $context): Result {
        $result = call_user_func($this->handler, $args, $context);

        if (!$result instanceof Result)
            throw new \UnexpectedValueException("Инструмент {$this->name} вернул не Result.");

        return $result;
    }
}
