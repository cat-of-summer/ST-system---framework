<?php

namespace ST_system\API\Drivers\AI;

use \ST_system\API\IntegrationDriver;
use \ST_system\Rule;

/**
 * Abstract base for OpenAI-compatible /chat/completions drivers.
 *
 * Concrete drivers must implement:
 *  - defaultModel(): string    — default model identifier
 *  - validateModel(string): bool — whether the model string is valid for this provider
 *
 * Optionally override:
 *  - $supportsStreaming (bool)  — true by default; set to false to lock stream=false
 */
abstract class OpenAICompatibleDriver extends IntegrationDriver {

    protected static array $CONFIG = [
        'endpoint'  => '',
        ',
    ];

    private array $SETTINGS = [];

    abstract protected function defaultModel(): string;
    abstract protected function validateModel(string $model): bool;

    protected bool $supportsStreaming = true;

    protected function __init(): void {
        // Register rule aliases (guarded, once per process)
        if (!Rule::get('float_range'))
            Rule::create(function(&$v, array $p): bool {
                if ($v === null) return true;
                $min = isset($p[0]) ? (float)$p[0] : PHP_INT_MIN;
                $max = isset($p[1]) ? (float)$p[1] : PHP_INT_MAX;
                return is_numeric($v) && (float)$v >= $min && (float)$v <= $max;
            })
            ->after(fn(&$v) => $v = $v === null ? null : (float)$v)
            ->handleError(fn($v) => 'Value out of range')
            ->alias('float_range');

        if (!Rule::get('message'))
            Rule::create(function(&$v): bool {
                if (!is_array($v) || !isset($v['role']) || !array_key_exists('content', $v))
                    throw new \Exception('Each message must be an array with keys role and content');
                if (!in_array($v['role'], ['system', 'user', 'assistant', 'tool'], true))
                    throw new \Exception('Message role must be one of: system, user, assistant, tool');
                return true;
            })
            ->handleError(fn($v) => 'Invalid message object')
            ->alias('message');

        if (!Rule::get('array_of_messages'))
            Rule::forEach(Rule::get('message'))
                ->order(800)
                ->handleError(fn($v) => 'The messages parameter is invalid or empty')
                ->alias('array_of_messages');

        $this->on('__construct', function (array $PARAMS = []) {
            $errors = Rule::object(['api_key' => 'required|string'])->apply($PARAMS);
            if (!empty($errors)) throw new \InvalidArgumentException($errors[0]);
            $this->SETTINGS = $PARAMS;
        });

        $this->on('prepare_response', function ($method, $params, &$raw_data) {
            if ($raw_data['http_code'] < 200 || $raw_data['http_code'] > 299)
                $raw_data['error'] = $raw_data['response'];
        });

        $this->on('before_curl_init', function ($r, $m, $p, &$config) {
            if (!empty($this->SETTINGS['api_key']))
                $config['headers']['Authorization'] = 'Bearer '.$this->SETTINGS['api_key'];
        });

        $defaultModel     = $this->defaultModel();
        $supportsStreaming = $this->supportsStreaming;

        $model_rule = Rule::create(fn(&$v) => is_string($v) && $this->validateModel($v))
            ->before(fn(&$v) => $v = $v ?? $defaultModel)
            ->handleError(fn($v) => "Недопустимая модель: {$v}");

        $stream_rule = Rule::create(function (&$v) use ($supportsStreaming): bool {
            $v = $supportsStreaming ? ($v === true) : false;
            return true;
        });

        $this->registerMethodsMap([
            'chat/completions' => [
                'method'       => 'POST',
                'content_type' => 'application/json',
                'params'       => [
                    'model'             => $model_rule,
                    'messages'          => 'required|array_of_messages',
                    'stream'            => $stream_rule,
                    'max_tokens'        => 'nullable|int|min:0',
                    'temperature'       => 'nullable|float_range:0,2',
                    'top_p'             => 'nullable|float_range:0,1',
                    'frequency_penalty' => 'nullable|float_range:-2,2',
                    'presence_penalty'  => 'nullable|float_range:-2,2',
                    'response_format'   => Rule::create(fn(&$v) => $v === null || is_array($v))
                                            ->handleError(fn($v) => 'response_format must be an array or null'),
                ],
            ],
        ]);
    }
}
