<?php

namespace ST_system\API\Drivers\AI;

/**
 * Groq Cloud API driver (chat completions).
 * Base URL: https://api.groq.com/openai/v1
 * Auth: Authorization: Bearer {API_KEY}
 */
final class Groq extends OpenAICompatibleDriver {

    protected static array $CONFIG = ['endpoint' => 'https://api.groq.com/openai/v1', '];

    protected function defaultModel(): string {
        return 'llama-3.3-70b-versatile';
    }

    protected function validateModel(string $model): bool {
        return is_string($model) && $model !== '';
    }
}
