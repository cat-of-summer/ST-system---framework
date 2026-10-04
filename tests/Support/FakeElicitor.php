<?php

namespace ST_system\Tests\Support;

use ST_system\MCP\Elicitation\Elicitor;

/**
 * Человек по сценарию: каждый вопрос забирает следующий заготовленный ответ
 * (['action' => …, 'content' => …]).
 */
final class FakeElicitor implements Elicitor {

    /** @var array<int,array{message:string,schema:array}> */
    public array $asked = [];

    private array $answers;
    private bool $supported;

    public function __construct(array $answers = [], bool $supported = true) {
        $this->answers   = $answers;
        $this->supported = $supported;
    }

    public function supported(): bool {
        return $this->supported;
    }

    public function ask(string $message, array $requestedSchema): array {
        $this->asked[] = ['message' => $message, 'schema' => $requestedSchema];

        if ($this->answers === [])
            throw new \LogicException("Лишний вопрос человеку: {$message}");

        return array_shift($this->answers);
    }
}
