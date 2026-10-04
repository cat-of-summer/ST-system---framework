<?php

namespace ST_system\MCP\Transport;

/** Поток сообщений сервера клиенту в рамках одного запроса (SSE-ответ на tools/call). */
interface Channel {

    public function send(array $message): void;

    /** Пустое событие, чтобы прокси не закрыл молчащее соединение. */
    public function ping(): void;

    /** Клиент ушёл — ждать его ответа бессмысленно. */
    public function closed(): bool;
}
