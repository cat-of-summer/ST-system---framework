<?php

namespace ST_system\API\Drivers;

use \ST_system\API\Integration_driver;

final class Telegram_bot extends Integration_driver {

    use \ST_system\API\Traits\Has_commands;
    use \ST_system\API\Traits\HTML_parser;

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

    private $token;
    private $command_handlers = [];

    protected function __init() {
        
        $this->on('__construct', function($token) {
            if (empty($token))
                new \Exception("Передан некорректный token");

            $this->token = $token;
        });

        $this->on('build_url', function (&$url, $point) {
            $url = str_replace($point, "{$point}{$this->token}", $url);
        });

        $this->on('call_method', function($method) {
            if (!$this->token)
                throw new \Exception("Для доступа методу '{$method}' необходим авторизационный токен!");
        });

        $validate_text = [
            'parse_mode' => [null, fn($v) => in_array($v, [null, 'HTML', 'Markdown', 'MarkdownV2'])],
            'disable_web_page_preview' => [false, fn($v) => is_bool($v)],
            'reply_to_message_id' => [null, fn($v) => is_null($v) || is_int($v)],
            'reply_markup' => [[], fn($v) => is_array($v), fn($v) => json_encode(self::prepare_params([
                'keyboard' => [[], fn($rows) => is_array($rows), fn($rows) => array_map(function ($row) {
                    if (!is_array($row))
                        throw new \Exception("Неправильная структура кнопок!");
                        
                    return array_map(fn($item) => self::prepare_params([
                        'text' => [new \Exception("Пустая кнопка!"), fn($v) => is_string($v) && !empty($v)],
                        'callback_data' => [null, fn($v) => is_string($v) && !empty($v)],
                        'url' => [null, fn($v) => filter_var($v, FILTER_VALIDATE_URL)],
                    ], $item), $row);
                } , $rows)],
                'resize_keyboard' => [true, fn($v) => is_bool($v)],
                'one_time_keyboard' => [false, fn($v) => is_bool($v)],
            ], $v))],
            'chat_id' => [new \Exception("Некорректный chat_id"), fn($v) => is_int($v)],
            'text' => [new \Exception("Некорректный text"), fn($v) => !empty($v), function($v, $p) {
                if ($p['parse_mode'] == 'HTML')
                    $v = self::normalize_html($v);
                    
                return $v;
            }],
        ];

        $this->register_methods_map([
            'getUpdates' => [
                'params' => [
                    'offset' => [0, fn($v) => is_int($v)],
                    'limit' => [100, fn($v) => is_int($v) && $v >= 1 && $v <= 100],
                    'timeout' => [0, fn($v) => is_int($v) && $v >= 0],
                    'allowed_updates' => [[], fn($v) => is_array($v)]
                ]
            ],
            'sendMessage' => [
                'params' => $validate_text
            ],
            'editMessageText' => [
                'params' => [
                    ...$validate_text,
                    'message_id' => [new \Exception("Некорректный message_id"), fn($v) => is_int($v)],
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

    protected function handle_response($response): string {
        return !isset($response['text']) || strpos($response['text'], '/') !== 0
            ? false
            : $response['text'];
    }

    public function get_updates(array $PARAMS = []) {
        static $last_update_id = 0;
        
        $response = $this->call_method('getUpdates', array_merge($PARAMS, ['offset' => $last_update_id]));

        if (
            !isset($response['ok']) ||
            $response['ok'] !== true ||
            !isset($response['result']) ||
            !is_array($response['result']) ||
            empty($response['result'])
        ) return [];

        $last_update_id = end($response['result'])['update_id'] + 1;

        return array_column($response['result'], 'message', 'update_id');
    }

    public function daemon(int $time_limit = 300) {
        if ($time_limit > 0)
            set_time_limit($time_limit);
        else
            set_time_limit(0);
        
        if ($time_limit > 0) {
            $endTime = time() + $time_limit;

            while (time() < $endTime)
                foreach ($this->get_updates() as $message)
                    $this->handle_input($message);
        } else
            while (true)
                foreach ($this->get_updates() as $message)
                    $this->handle_input($message);
    }
}