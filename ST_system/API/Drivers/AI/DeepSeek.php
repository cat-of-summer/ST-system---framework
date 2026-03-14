<?php

namespace ST_system\API\Drivers\AI;

/**
 * DeepSeek API driver (chat completions).
 * Base URL: https://api.deepseek.com/v1
 * Auth: Authorization: Bearer {API_KEY}
 * Note: streaming is disabled for DeepSeek (non-streaming only).
 */
final class DeepSeek extends OpenAICompatibleDriver {

    protected const DEFAULT_ENDPOINT = 'https://api.deepseek.com/v1';

    protected function defaultModel(): string {
        return 'deepseek-chat';
    }

    protected function validateModel(string $model): bool {
        return in_array($model, ['deepseek-chat', 'deepseek-reasoner'], true);
    }

    protected bool $supportsStreaming = false;
}
