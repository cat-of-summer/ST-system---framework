<?php

namespace ST_system\API\Drivers\AI;

use \ST_system\Rule;

final class Mistral extends OpenAICompatibleDriver {

    private static array $INSTANCES = [];

    protected static array $CONFIG = [
        'endpoint' => 'https://api.mistral.ai/v1/chat/',
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

    private string $token = '';
    private array $conversation = [];
    private array $usage = [];

    protected function __init(): void {

        $RULES = [
            'messages' => ['required', Rule::create(fn(&$v) => is_array($v) && count($v) > 0)->handleError(fn($v) => 'Параметр messages должен быть непустым массивом!')],
            'temperature' => ['nullable', Rule::create(fn(&$v) => is_numeric($v) && $v >= 0 && $v <= 2)->handleError(fn($v) => 'temperature должен быть числом от 0 до 2')],
            'model' => ['default:'.static::config('models.0'), Rule::create(fn(&$v) => in_array($v, static::config('models')))->handleError(fn($v) => "Недопустимая модель '{$v}'")],
        ];

        $this->on('__construct', function(array $config) {
            $errors = Rule::object([
                'token' => Rule::create(['required', 'string']),
                'alias' => 'nullable|string',
            ])
            ->handleError(fn($v, $errors) => throw new \Exception(implode(', ', $errors)))
            ->apply($config);

            $this->token = $config['token'];

            if (isset($config['alias'])) {
                if (isset(static::$INSTANCES[$config['alias']]))
                    throw new \Exception("Алиас '{$config['alias']}' уже занят в " . static::class);

                static::$INSTANCES[$config['alias']] = $this;
            } else {
                static::$INSTANCES[] = $this;
            }
        });

        $this->on('before_curl_init', function(&$request_url, $method, &$params, &$config) {
            $config['headers']['Authorization'] = 'Bearer ' . $this->token;
        });

        $this->registerMethod('completions', [
            'method'       => 'POST',
            'content_type' => 'application/json',
            'params' => [
                'model'       => $RULES['model'],
                'messages'    => $RULES['messages'],
                'temperature' => $RULES['temperature'],
                'max_tokens'  => 'nullable|int',
                'stream'      => 'nullable|bool',
            ]
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
                    'role'    => 'required|string',
                    'content' => 'required|string',
                ]))->apply($v);
            })->handleError(fn($v, $errors) => throw new \Exception(implode(', ', $errors)));

        $rule->apply($input);

        array_push($this->conversation, ...$input);

        $response = $this->call('completions', array_merge($options, ['messages' => ($options['conversation'] ?? false) ? $input : $this->conversation]));

        array_push($this->conversation, $response['choices'][0]['message'] ?? []);

        if (!empty($response['usage']))
            $this->usage[] = array_merge([
                'id' => $response['id'],
                'created' => $response['created'],
            ], $response['usage']);
        
        return $response['choices'][0]['message']['content'] ?? '';
    }
}
