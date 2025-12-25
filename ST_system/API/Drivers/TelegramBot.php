<?php

namespace ST_system\API\Drivers;

use \ST_system\API\IntegrationDriver;

final class TelegramBot extends IntegrationDriver {

    use \ST_system\API\Drivers\Traits\HasHTMLRules;
    
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

    protected function __init() {

        static::register_rules_map([
            'parse_mode' => [null, fn($v) => in_array($v, [null, 'HTML', 'Markdown', 'MarkdownV2'])],
            'html' => [...static::rule('string'), 'after' => fn($v, $k, $p) => ($p['parse_mode'] ?? '') == 'HTML' ? self::normalize_html($v) : $v],
            '*html' => [...static::rule('*string'), 'after' => fn($v, $k, $p) => ($p['parse_mode'] ?? '') == 'HTML' ? self::normalize_html($v) : $v],
            'inline_keyboard' => [null, fn($v) => is_array($v), 'after' => fn($v) => array_map(fn($i) => array_map(fn($j) => static::prepare_params([
                'text' => '*string',
                'url' => 'url',
                'callback_data' => 'string'
            ], $j, function(&$params) {
                if (!empty($params['url']))
                    unset($params['callback_data']);
            }), $i), $v)],
            'keyboard' => [null, fn($v) => is_array($v), 'after' => fn($v) => array_map(fn($i) => array_map(fn($j) => static::prepare_params([
                'text' => '*string',
            ], $j), $i ?? []), $v ?? [])],
            'reply_markup' => [null, fn($v) => is_array($v), 'after' => fn($v) => json_encode(static::prepare_params([
                'inline_keyboard' => 'inline_keyboard',
                'keyboard' => 'keyboard',
                'resize_keyboard' => 'bool',
                'one_time_keyboard' => 'bool',
                'is_persistent' => 'bool'
            ], $v, function(&$params) {
                if (!empty($params['inline_keyboard'])) {
                    unset($params['keyboard']);
                    unset($params['resize_keyboard']);
                    unset($params['one_time_keyboard']);
                    unset($params['is_persistent']);
                }
            }))]
        ]);

        $this->on('__construct', function(string $token) {
            $this->token = $token;
        });

        $this->on('build_url', function (&$request_url, $point) {
            $request_url = str_replace($point, "{$point}{$this->token}", $request_url);
        });

        $this->on('before_call', function($method) {
            if ($this->token == '')
                throw new \Exception("Для доступа методу '{$method}' необходим авторизационный токен!");
        });

        $this->register_methods_map([
            'sendMessage' => [
                'params' => [
                    'parse_mode' => 'parse_mode',
                    'chat_id' => '*int',
                    'text' => '*html',
                    'reply_markup' => 'reply_markup'
                ]
            ],
            'sendPhoto' => [
                'params' => [
                    'parse_mode' => 'parse_mode',
                    'chat_id' => '*int',
                    'photo' => '*url',
                    'caption' => 'html',
                    'reply_markup' => 'reply_markup'
                ]
            ],
            'sendVideo' => [
              'params' => [
                    'parse_mode' => 'parse_mode',
                    'chat_id' => '*int',
                    'video' => '*url',
                    'thumbnail' => 'url',
                    'caption' => 'html',
                    'reply_markup' => 'reply_markup'
                ]
            ],
            'sendMediaGroup' => [
                'params' => [
                    'chat_id' => '*int',
                    'media' => [new \Exception("Не передан массив медиа-контента или он некорректен!"), fn($v) => is_array($v) && count($v) >= 2 && count($v) <= 10, 'after' => fn($v) => json_encode(array_map(fn($j) => static::prepare_params([
                        'type' => [new \Exception("Не передан тип медиа-контента!"), fn($v) => in_array($v, ['audio', 'document', 'photo', 'video'])],
                        'parse_mode' => 'parse_mode',
                        'media' => '*url',
                        'thumbnail' => 'url',
                        'caption' => 'html',
                    ], $j), $v))],
                ]
            ],
            // 'editMessageText' => [
            //     'params' => [
            //         ...$this->method_config('sendMessage')['params'],
            //         'message_id' => [new \Exception("Некорректный message_id"), fn($v) => is_int($v)],
            //     ]
            // ],
            // 'deleteMessage' => [
            //     'params' => [
            //         'chat_id' => [new \Exception("Некорректный chat_id"), fn($v) => is_int($v)],
            //         'message_id' => [new \Exception("Некорректный message_id"), fn($v) => is_int($v)],
            //     ]
            // ],
            'answerCallbackQuery' => [
                'params' => [
                    'callback_query_id' => '*string',
                    'text' => 'string'
                ]
            ],
            'setWebhook' => [
                'params' => [
                    'url' => '*secure_url',
                    'show_alert' => 'bool'
                ]
            ],
            'deleteWebhook' => [],
            'getWebhookInfo' => []
        ]);

    }    
}