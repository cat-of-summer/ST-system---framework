<?php

namespace ST_system\API\Drivers\AI;

/**
 * Mistral AI API driver (chat completions).
 * Base URL: https://api.mistral.ai/v1
 * Auth: Authorization: Bearer {API_KEY}
 */
final class Mistral extends OpenAICompatibleDriver {

    protected static array $CONFIG = ['endpoint' => 'https://api.mistral.ai/v1', '];

    protected function defaultModel(): string {
        return 'mistral-small-latest';
    }

    protected function validateModel(string $model): bool {
        return (bool)preg_match('/^(mistral|open-mistral|open-mixtral)-[a-z0-9.-]+$/i', $model);
    }
}
