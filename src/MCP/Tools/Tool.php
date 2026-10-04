<?php

namespace ST_system\MCP\Tools;

use ST_system\MCP\Context;
use ST_system\MCP\Result;

/**
 * Инструмент MCP. Название, описание и схема параметров уходят агенту в tools/list и
 * в справочник ToolsDoc — других копий этих текстов нет.
 *
 * Обязательны имя, описание и call; остальное имеет умолчания: параметров нет, аннотаций
 * нет, ответ JSON без SSE-потока.
 */
abstract class Tool {

    /** Имя для вызова: латиница, цифры, _ и -. Префикс группы Server::prefix() добавит сервер. */
    abstract public function name(): string;

    /** Для модели: когда вызывать, что вернёт, что делать дальше. */
    abstract public function description(): string;

    /** Аргументы уже проверены по inputSchema, умолчания подставлены. */
    abstract public function call(array $args, Context $context): Result;

    /** Короткое название для человека. */
    public function title(): string {
        return $this->name();
    }

    /** JSON Schema параметров (type: object); у каждого свойства — description. */
    public function inputSchema(): array {
        return ['type' => 'object', 'properties' => []];
    }

    /**
     * Дополнительные правила Rule по именам параметров — то, чего нет в JSON Schema:
     * ['email' => 'trim|email', 'name' => ['trim', Rule::create(fn (&$v) => …)]].
     * Выполняются вместе с проверкой inputSchema, ошибки уходят модели.
     */
    public function rules(): array {
        return [];
    }

    /** JSON Schema для structuredContent; null — не объявлять. */
    public function outputSchema(): ?array {
        return null;
    }

    /** readOnlyHint / destructiveHint / idempotentHint / openWorldHint. */
    public function annotations(): array {
        return [];
    }

    /** Спрашивает ли инструмент человека (для справочника). */
    public function asksHuman(): bool {
        return false;
    }

    /**
     * Отвечать ли SSE-потоком. Нужен, когда инструмент спрашивает человека (elicitation,
     * Context::confirm) или долго ждёт: в поток идут вопросы и ping.
     */
    public function streams(): bool {
        return false;
    }
}
