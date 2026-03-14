<?php

namespace ST_system\API\Drivers\AI;

/**
 * Grok (xAI) API driver (chat completions).
 * Base URL: https://api.x.ai/v1
 * Auth: Authorization: Bearer {API_KEY}
 */
final class Grok extends OpenAICompatibleDriver {

    protected const DEFAULT_ENDPOINT = 'https://api.x.ai/v1';

    protected function defaultModel(): string {
        return 'grok-2';
    }

    protected function validateModel(string $model): bool {
        return (bool)preg_match('/^grok-[a-z0-9-]+$/i', $model);
    }
}
