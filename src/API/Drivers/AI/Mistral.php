<?php

namespace ST_system\API\Drivers\AI;

use ST_system\Rule;
use ST_system\Main;

final class Mistral extends OpenAICompatibleDriver {

    private static array $INSTANCES = [];

    protected static function getDefaultConfig(): array {
        return [
            'endpoint' => 'https://api.mistral.ai/v1/chat/',
            'cache' => [
                'use' => true
            ],
            'models' => [
                "mistral-small-latest",
                "mistral-medium-latest",
                "mistral-large-latest",

                "open-mistral-7b",
                "open-mixtral-8x7b",

                "codestral-latest",

                "pixtral-12b",
                "pixtral-large-latest",

                "ministral-3b-latest",
                "ministral-8b-latest"
            ]
        ];
    }

    private string $token = '';
    private string $alias = '';
    private array $conversation = [];
    private array $usage = [];

    protected function __init(): void {
        $this->on('__construct', function(array $config) {
            Rule::object([
                'token' => Rule::create(['required', 'string']),
                'alias' => 'nullable|string',
            ])->throwable()->apply($config);

            $this->token = $config['token'];

            if (isset($config['alias'])) {
                if (isset(static::$INSTANCES[$config['alias']]))
                    throw new \Exception("Алиас '{$config['alias']}' уже занят в " . static::class);

                static::$INSTANCES[$config['alias']] = $this;

                $this->alias = $config['alias'];
                $this->conversation = $this->alias ? ($this->cache()->make(static::class)->get($this->alias) ?? []) : [];
            } else {
                static::$INSTANCES[] = $this;
            }
        });

        $this->on('before_curl_init', function(&$request_url, $method, &$params, &$config) {
            $config['headers']['Authorization'] = 'Bearer ' . $this->token;
        });

        Rule::create(fn(&$v) => is_array($v) && count($v) > 0)
            ->handleError(fn($v) => 'Параметр messages должен быть непустым массивом!')
            ->alias('messages', 1);

        Rule::create(fn(&$v) => is_numeric($v) && $v >= 0 && $v <= 2)
            ->handleError(fn($v) => 'temperature должен быть числом от 0 до 2')
            ->alias('temperature', 1);

        Rule::create(fn(&$v) => in_array($v, static::config('models'), true))
            ->handleError(fn($v) => "Недопустимая модель '{$v}'")
            ->alias('model', 1);

        $this->registerMethod('completions', [
            'method'       => 'POST',
            'content_type' => 'application/json',
            'params' => [
                'model'       => 'default:'.static::config('models.0').'|model',
                'messages'    => 'required|messages',
                'temperature' => 'nullable|temperature',
                'max_tokens'  => 'nullable|int',
                'stream'      => 'nullable|bool',
            ],
            'cache_ttl' => 3600
        ]);
    }

    public function ask($input, array $options = []): string {
        static $rule = null;

        if ($rule === null)
            $rule = Rule::create(function(&$v) {
                if (is_string($v))
                    $v = [['role' => 'user', 'content' => $v]];
                elseif (is_array($v) && isset($v['role']))
                    $v = [$v];
                elseif (!is_array($v))
                    return false;

                return Rule::forEach(Rule::object([
                    'role'    => 'required|string|in:assistant,system,user',
                    'content' => 'required|string',
                    'prefix' => 'sometimes|bool'
                ]))->apply($v);
            })->handleError(function ($v, $errors) {
                throw new \Exception(implode(', ', $errors));
            });

        $rule->apply($input);

        $conversation = $options['conversation'] ?? false;

        if ($conversation)
            array_push($this->conversation, ...$input);

        $response = $this->call('completions', array_merge($options, ['messages' => !$conversation ? $input : $this->conversation]));

        if ($conversation) {
            array_push($this->conversation, $response['choices'][0]['message'] ?? []);

            if ($this->alias)
                $this->cache()->make(static::class)->set($this->conversation, 0, $this->alias);
        }

        if (!empty($response['usage']))
            $this->usage[] = array_merge([
                'id' => $response['id'],
                'created' => $response['created'],
            ], $response['usage']);

        return $response['choices'][0]['message']['content'] ?? '';
    }

    public function getHistory(int $count = 0, int $start = 0): array {
        return array_slice($this->conversation, $start, $count ?: null);
    }

    
    public function getHistorySize(string $unit = 'b') {
        return Main::formatBytes(mb_strlen(json_encode($this->conversation), '8bit'), $unit);
    }

    public function clearHistory(int $count = 0, int $start = 0): void {
        if ($count === 0)
            $this->conversation = [];
        else
            array_splice($this->conversation, $start, $count);

        if ($this->alias)
            $this->cache()->make(static::class)->set($this->conversation, 0, $this->alias);
    }
}
