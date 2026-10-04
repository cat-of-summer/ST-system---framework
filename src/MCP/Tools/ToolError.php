<?php

namespace ST_system\MCP\Tools;

/**
 * Отказ операции, понятный модели: сервер вернёт его текст результатом с isError, а не
 * «внутренней ошибкой». Бросать там, где модель может исправить вызов или выбрать другой
 * путь: не найдено, нет прав, неподходящее состояние.
 */
class ToolError extends \RuntimeException {

    /** @var ?array */
    private $data;

    public function __construct(string $message, ?array $data = null, int $code = 0, ?\Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
        $this->data = $data;
    }

    /** Данные для structuredContent результата. */
    public function getData(): ?array {
        return $this->data;
    }
}
