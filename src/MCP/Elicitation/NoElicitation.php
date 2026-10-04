<?php

namespace ST_system\MCP\Elicitation;

/** Клиент не умеет elicitation или ответ идёт JSON без потока: спросить в диалоге нельзя. */
final class NoElicitation implements Elicitor {

    public function supported(): bool {
        return false;
    }

    public function ask(string $message, array $requestedSchema): array {
        return ['action' => 'cancel'];
    }
}
