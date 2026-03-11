<?php

namespace ST_system\API\Drivers\AI;

use \ST_system\API\IntegrationDriver;

/**
 * Groq Cloud API driver (chat completions).
 * Base URL: https://api.groq.com/openai/v1
 * Auth: Authorization: Bearer {API_KEY}
 * Streaming (stream: true) is supported; use stream => true for streaming responses.
 */
final class Groq extends IntegrationDriver {

    protected const DEFAULT_POINT = 'https://api.groq.com/openai/v1';
    protected const CACHE_DIRECTORY = '';

    private $SETTINGS = [];

    protected function __init() {
        static::register_rules_map([
            'array_of_messages' => [
                fn($k) => new \Exception("Не передан параметр {$k}"),
                fn($v) => is_array($v) && count($v) >= 1,
                fn($v) => array_map(function ($item) {
                    if (!is_array($item) || !isset($item['role']) || !array_key_exists('content', $item)) {
                        throw new \Exception('Каждое сообщение должно быть массивом с ключами role и content');
                    }
                    $role = $item['role'];
                    if (!in_array($role, ['system', 'user', 'assistant', 'tool'], true)) {
                        throw new \Exception("Роль сообщения должна быть одна из: system, user, assistant, tool");
                    }
                    return $item;
                }, $v),
            ],
        ]);

        $this->on('__construct', function (array $PARAMS = []) {
            $this->SETTINGS = static::prepare_params([
                'api_key' => '*string',
            ], $PARAMS);
        });

        $this->on('prepare_response', function ($method, $params, &$raw_data) {
            if ($raw_data['http_code'] < 200 || $raw_data['http_code'] > 299) {
                $raw_data['error'] = $raw_data['response'];
            }
        });

        $this->on('before_curl_init', function ($r, $m, $p, &$config) {
            if (!empty($this->SETTINGS['api_key'])) {
                $config['headers']['Authorization'] = 'Bearer ' . $this->SETTINGS['api_key'];
            }
        });

        $this->register_methods_map([
            'chat/completions' => [
                'method' => 'POST',
                'content_type' => 'application/json',
                'params' => [
                    'model' => ['llama-3.3-70b-versatile', fn($v) => is_string($v) && $v !== ''],
                    'messages' => 'array_of_messages',
                    'stream' => [false, fn($v) => $v === false || $v === true || $v === null, 'after' => fn($v) => $v === true],
                    'max_tokens' => [null, fn($v) => $v === null || (is_int($v) && $v >= 0)],
                    'temperature' => [1.0, fn($v) => is_numeric($v) && (float)$v >= 0 && (float)$v <= 2, fn($v) => $v === null ? 1.0 : (float)$v],
                    'top_p' => [1.0, fn($v) => $v === null || (is_numeric($v) && (float)$v >= 0 && (float)$v <= 1), fn($v) => $v === null ? 1.0 : (float)$v],
                    'frequency_penalty' => [0, fn($v) => $v === null || (is_numeric($v) && (float)$v >= -2 && (float)$v <= 2), fn($v) => $v === null ? 0.0 : (float)$v],
                    'presence_penalty' => [0, fn($v) => $v === null || (is_numeric($v) && (float)$v >= -2 && (float)$v <= 2), fn($v) => $v === null ? 0.0 : (float)$v],
                    'response_format' => [null, fn($v) => $v === null || is_array($v)],
                ],
            ],
        ]);
    }
}
