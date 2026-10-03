<?php

namespace ST_system\Exceptions;

class ValidationException extends \Exception {

    private array $errors = [];

    /**
     * @param string[] $messages ошибки Rule в виде "поле.текст"; без точки — под ключом ''
     */
    public function __construct(array $messages, int $code = 422, ?\Throwable $previous = null) {
        parent::__construct(implode(PHP_EOL, $messages), $code, $previous);

        foreach ($messages as $message) {
            $message = (string)$message;
            $dot     = strpos($message, '.');

            if ($dot === false) $this->errors[''][] = $message;
            else                $this->errors[substr($message, 0, $dot)][] = substr($message, $dot + 1);
        }
    }

    /** @return array<string, string[]> поле => [сообщения] */
    public function getErrors(): array {
        return $this->errors;
    }
}
