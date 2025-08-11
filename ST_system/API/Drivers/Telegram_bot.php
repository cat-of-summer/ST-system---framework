<?php

namespace ST_system\API\Drivers;

use \ST_system\API\Integration_driver;
use ST_system\Debug;

final class Telegram_bot extends Integration_driver {

    use \ST_system\API\Traits\Has_commands;
    use \ST_system\API\Traits\HTML_parser;

    /*HTML_parser*/
    protected static function get_nodes_map(): array {

        $line_break = fn($content) => "$content\n";

        return [
            'br' => "\n",
            'div' => $line_break,
            'form' => false,
            'table' => false,
            'img' => false,
            'b' => true,
            'strong' => true,
            'i' => true,
            'em' => true,
            'u' => true,
            'ins' => true,
            's' => true,
            'strike' => true,
            'del' => true,
            'a' => true,
            'code' => true,
            'pre' => true,
            'tg-spoiler' => true,
            'span' => fn($content, $attrs) => isset($attrs['class']) && strpos($attrs['class'], 'tg-spoiler') !== false
                ? "<tg-spoiler>$content</tg-spoiler>"
                : $line_break($content),
            'h1' => fn($content) => $line_break("<b>$content</b>"),
            'h2' => fn($content) => $line_break("<b>$content</b>"),
            'h3' => $line_break,
            'h4' => $line_break,
            'h5' => $line_break,
            'h6' => $line_break,
            'li' => fn($content) => "— $content\n",
            'p' => $line_break,
            'dt' => fn($content) => "• $content: ",
            'dd' => $line_break,
        ];

    }

    protected const DEFAULT_POINT = 'https://api.telegram.org/bot';

    private string $token;

    private array $keyboards = [];
    private array $keyboard = [];

    protected function __init() {
        
        $this->on('__construct', function($token) {
            if (empty($token))
                new \Exception("Передан некорректный token");

            $this->token = $token;
        });

        $this->on('build_url', function (&$url, $point) {
            $url = str_replace($point, "{$point}{$this->token}", $url);
        });

        $this->on('call', function($method) {
            if (!$this->token)
                throw new \Exception("Для доступа методу '{$method}' необходим авторизационный токен!");
        });

        $this->on('call', function($method, &$params) {
            if (in_array($method, ['sendMessage', 'editMessageText', 'sendPhoto'])) {
                $params = array_merge($params, $this->keyboard);
                $this->keyboard = [];               
            }

            if ($method == 'setMyCommands')
                \ST_system\Debug::dump_to_file($params);
        });

        $this->register_method('sendMessage', [
            'params' => [
                'parse_mode' => [null, fn($v) => in_array($v, [null, 'HTML', 'Markdown', 'MarkdownV2'])],
                'disable_web_page_preview' => [false, fn($v) => is_bool($v)],
                'reply_to_message_id' => [null, fn($v) => is_null($v) || is_int($v)],
                'chat_id' => [new \Exception("Некорректный chat_id"), fn($v) => is_int($v)],
                'text' => [new \Exception("Некорректный text"), fn($v) => !empty($v), function($v, $p) {
                    if ($p['parse_mode'] == 'HTML')
                        $v = self::normalize_html($v); /*HTML_parser*/
                        
                    return $v;
                }],
            ]
        ]);

        $this->register_methods_map([
            'getUpdates' => [
                'params' => [
                    'offset' => [0, fn($v) => is_int($v)],
                    'limit' => [100, fn($v) => is_int($v) && $v >= 1 && $v <= 100],
                    'timeout' => [0, fn($v) => is_int($v) && $v >= 0],
                    'allowed_updates' => [[], fn($v) => is_array($v)]
                ]
            ],
            'setMyCommands' => [
                'params' => [
                    'commands' => [[], fn($commands) => is_array($commands), fn($commands) => json_encode(array_map(fn($command) => self::prepare_params([
                        'command' => [throw new \Exception("Комманда не передана!"), fn($v) => is_scalar($v) && !empty($v), fn($v) => ltrim($v, '/')],
                        'description' => [null, fn($v) => is_scalar($v) && !empty($v)]
                    ], $command), $commands))]
                ]
            ],
            'editMessageText' => [
                'params' => [
                    ...$this->method_config('sendMessage')['params'],
                    'message_id' => [new \Exception("Некорректный message_id"), fn($v) => is_int($v)],
                ]
            ],
            'sendPhoto' => [
                'params' => [
                    'chat_id' => [new \Exception("Некорректный chat_id"), fn($v) => is_int($v)],
                    'photo' => [new \Exception("Некорректное фото"), fn($v) => filter_var($v, FILTER_VALIDATE_URL)],
                    'caption' => [null, fn($v) => is_scalar($v) && !empty($v)]
                ]
            ],
            'deleteMessage' => [
                'params' => [
                    'chat_id' => [new \Exception("Некорректный chat_id"), fn($v) => is_int($v)],
                    'message_id' => [new \Exception("Некорректный message_id"), fn($v) => is_int($v)],
                ]
            ],
            'setWebhook' => [
                'params' => [
                    'url' => [new \Exception("Некорректный url."), fn($v) => filter_var($v, FILTER_VALIDATE_URL)],
                ]
            ],
            'deleteWebhook' => []
        ]);

    }

    public function get_updates(array $PARAMS = []) {
        static $last_update_id = 0;
        
        $response = $this->call('getUpdates', array_merge($PARAMS, ['offset' => $last_update_id]));

        if (
            !isset($response['ok']) ||
            $response['ok'] !== true ||
            !isset($response['result']) ||
            !is_array($response['result']) ||
            empty($response['result'])
        ) return [];

        $last_update_id = end($response['result'])['update_id'] + 1;

        return $response['result'];
    }

    public function add_keyboard_map(array $keyboards) {
        array_walk($keyboards, fn($params, $name) => $this->add_keyboard($name, $params));
    }

    public function add_keyboard(string $name, array $PARAMS) {

        switch (true) {
            case isset($PARAMS['inline_keyboard']):
                self::prepare_params([
                    'inline_keyboard' => [[], fn($rows) => is_array($rows), fn($rows) => array_map(function ($row) {
                        if (!is_array($row))
                            throw new \Exception("Неправильная структура кнопок!");
                            
                        return array_map(fn($item) => self::prepare_params([
                            'text' => [new \Exception("Пустая кнопка!"), fn($v) => is_string($v) && !empty($v)],
                            'callback_data' => [null, fn($v) => is_string($v) && !empty($v)],
                            'url' => [null, fn($v) => filter_var($v, FILTER_VALIDATE_URL)],
                        ], $item, function($p) {
                            if (empty($p['callback_data']) && empty($p['url']))
                                throw new \Exception("Некорректный callback_data или url");
                        }), $row);
                    } , $rows)],
                ], $PARAMS);
                break;
            case isset($PARAMS['keyboard']):
                self::prepare_params([
                    'keyboard' => [[], fn($rows) => is_array($rows), fn($rows) => array_map(fn($row) => array_map(fn($button) => self::prepare_params([new \Exception('Не задан текст кнопки!'), is_string($button) && $button != ''], $button), $row), $rows)],
                    'resize_keyboard' => [true, fn($v) => is_bool($v)],
                    'one_time_keyboard' => [false, fn($v) => is_bool($v)],
                ], $PARAMS);
                break;
            default:
                throw new \Exception("Неизвестный тип клавиатуры!");     
        }

        $this->keyboards[$name] = ['reply_markup' => json_encode($PARAMS)];
    }

    public function use_keyboard(string $name) {
        if (!isset($this->keyboards[$name]))
            throw new \Exception("Клавиатура {$name} не добавлена!");
            
        $this->keyboard = $this->keyboards[$name];
    }

    public function remove_keyboard() {
        $this->keyboard = ['reply_markup' => json_encode(['remove_keyboard' => true])];
    }

    public function keyboard($param1, array $param2 = []) {
        if (!empty($param2))
            $this->add_keyboard($param1, $param2);

        if (is_scalar($param1))
            $this->use_keyboard($param1);
        else
            $this->remove_keyboard();
    }


    /*Has_commands*/
    protected function handle_response($response): array {
        $is_callback_query = isset($response['callback_query']);

        return [
            0 => $is_callback_query
                ? $response['callback_query']['data']
                : $response['message']['text'],
            'chat_id' => $is_callback_query
                ? $response['callback_query']['message']['chat']['id']
                : $response['message']['chat']['id'],
            'message_id' => $is_callback_query
                ? $response['callback_query']['message']['message_id']
                : $response['message']['message_id']
        ];
    }

    /*Has_commands*/
    protected function handle_updates(): array {
        return $this->get_updates();
    }

    public function add_page() {

    }
    
}