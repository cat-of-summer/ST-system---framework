<?php

namespace ST_system\MCP\Elicitation;

/**
 * Вопрос человеку через клиента MCP (elicitation/create, режим формы).
 *
 * ask возвращает ['action' => accept|decline|cancel|timeout, 'content' => [...]]:
 * accept — человек заполнил форму, decline — явно отказался, cancel — закрыл окно,
 * timeout — не ответил за отведённое время или клиент отключился.
 */
interface Elicitor {

    public function supported(): bool;

    /** @return array{action:string,content?:array} */
    public function ask(string $message, array $requestedSchema): array;
}
