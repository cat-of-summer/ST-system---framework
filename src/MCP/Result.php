<?php

namespace ST_system\MCP;

/**
 * Результат инструмента: текст для модели и те же данные в structuredContent.
 * Текст — первое, что читает модель, поэтому в нём сказано и что произошло, и что делать
 * дальше; JSON ниже — для точных значений (id, статусы).
 */
final class Result {

    /** @var string */
    public $text;
    /** @var ?array */
    public $data;
    /** @var bool */
    public $error;

    private function __construct(string $text, ?array $data, bool $error) {
        $this->text  = $text;
        $this->data  = $data;
        $this->error = $error;
    }

    public static function ok(string $text, ?array $data = null): self {
        return new self($text, $data, false);
    }

    public static function error(string $text, ?array $data = null): self {
        return new self($text, $data, true);
    }

    public function toArray(): array {
        $text = $this->text;

        if ($this->data !== null)
            $text .= "\n\n".json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $result = ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $this->error];

        if ($this->data !== null)
            $result['structuredContent'] = $this->data === [] ? new \stdClass() : $this->data;

        return $result;
    }
}
