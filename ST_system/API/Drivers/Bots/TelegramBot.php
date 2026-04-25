<?php

namespace ST_system\API\Drivers\Bots;

use \ST_system\API\IntegrationDriver;
use \ST_system\Rule;

final class TelegramBot extends IntegrationDriver {

    use \ST_system\API\Drivers\Traits\HasHTMLRules;
    
    protected static function getHtmlRules(): array {

        $line_break = fn($content) => "$content\n";

        return [
            'br' => "\n",
            'div' => $line_break,
            'form' => false,
            'table'  => fn($content) => trim($content) !== '' ? "$content\n" : '',
            'tbody'  => fn($content) => $content,
            'thead'  => fn($content) => $content,
            'tfoot'  => fn($content) => $content,
            'tr'     => fn($content) => trim($content) !== '' ? trim($content)."\n" : '',
            'td'     => fn($content) => trim($content) !== '' ? trim($content).' ' : '',
            'th'     => fn($content) => trim($content) !== '' ? '<b>'.trim($content).'</b> ' : '',
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

    protected static function getDefaultConfig(): array { return ['endpoint' => 'https://api.telegram.org/bot']; }

    private string $token;

    private static function process_inline_keyboard(array $rows): array {
        return array_map(fn($row) => array_map(function ($btn) {
            $errors = Rule::object(['text' => 'required|string', 'url' => 'nullable|url', 'callback_data' => 'nullable|string'])->apply($btn);
            if (!empty($errors)) throw new \InvalidArgumentException($errors[0]);
            if (!empty($btn['url'])) unset($btn['callback_data']);
            return $btn;
        }, $row), $rows);
    }

    private static function process_keyboard(array $rows): array {
        return array_map(fn($row) => array_map(function ($btn) {
            $errors = Rule::object(['text' => 'required|string'])->apply($btn);
            if (!empty($errors)) throw new \InvalidArgumentException($errors[0]);
            return $btn;
        }, $row ?: []), $rows ?: []);
    }

    private static function process_reply_markup(array $v): string {
        $schema = [
            'inline_keyboard'   => Rule::create(fn(&$v) => is_array($v))->after(fn(&$v) => $v = self::process_inline_keyboard($v)),
            'keyboard'          => Rule::create(fn(&$v) => is_array($v))->after(fn(&$v) => $v = self::process_keyboard($v)),
            'resize_keyboard'   => 'nullable|bool',
            'one_time_keyboard' => 'nullable|bool',
            'is_persistent'     => 'nullable|bool',
        ];
        $errors = Rule::object($schema)->apply($v);
        if (!empty($errors)) throw new \InvalidArgumentException($errors[0]);
        if (!empty($v['inline_keyboard']))
            unset($v['keyboard'], $v['resize_keyboard'], $v['one_time_keyboard'], $v['is_persistent']);
        return json_encode($v);
    }

    protected function __init(): void {
        if (!Rule::get('tg_parse_mode'))
            Rule::create(fn(&$v) => $v === null || in_array($v, ['HTML', 'Markdown', 'MarkdownV2'], true))
                ->handleError(fn($v) => 'Invalid parse_mode')
                ->alias('tg_parse_mode');

        if (!Rule::get('tg_reply_markup'))
            Rule::create(fn(&$v) => $v === null || is_array($v))
                ->handleError(fn($v) => 'reply_markup must be an array')
                ->after(fn(&$v) => $v = is_array($v) ? self::process_reply_markup($v) : $v)
                ->alias('tg_reply_markup');

        if (!Rule::get('tg_media_group'))
            Rule::object([
                'type' => Rule::create(fn(&$v) => in_array($v, ['audio', 'document', 'photo', 'video'], true))
                    ->handleError(fn($v) => 'Не передан тип медиа-контента или он некорректен!'),
                'parse_mode' => 'nullable|tg_parse_mode',
                'media'      => 'required|url',
                'thumbnail'  => 'nullable|url',
                'caption'    => 'nullable|string',
            ])
            ->alias('tg_media_group');

        $this->on('__construct', function(string $token) {
            $this->token = $token;
        });

        $this->on('build_url', function (&$request_url, $endpoint) {
            $request_url = str_replace($endpoint, "{$endpoint}{$this->token}", $request_url);
        });

        $this->on('before_call', function($method) {
            if ($this->token == '')
                throw new \Exception("Для доступа методу '{$method}' необходим авторизационный токен!");
        });

        $html_on_prepare = function(&$params, string $field = 'text') {
            if (($params['parse_mode'] ?? '') === 'HTML' && isset($params[$field]))
                $params[$field] = self::normalizeHtml($params[$field]);
        };

        $this->registerMethodsMap([
            'getUpdates' => [
                'params' => [
                    'offset'       => 'sometimes|int'
                ]
            ],
            'sendMessage' => [
                'params' => [
                    'chat_id'      => 'required|int',
                    'text'         => 'required|string',
                    'parse_mode'   => 'nullable|tg_parse_mode',
                    'reply_markup' => 'nullable|tg_reply_markup',
                ],
                'on_prepare' => fn(&$p) => $html_on_prepare($p, 'text'),
            ],
            'sendPhoto' => [
                'params' => [
                    'chat_id'      => 'required|int',
                    'photo'        => 'required|url',
                    'caption'      => 'nullable|string',
                    'parse_mode'   => 'nullable|tg_parse_mode',
                    'reply_markup' => 'nullable|tg_reply_markup',
                ],
                'on_prepare' => fn(&$p) => $html_on_prepare($p, 'caption'),
            ],
            'sendVideo' => [
                'params' => [
                    'chat_id'      => 'required|int',
                    'video'        => 'required|url',
                    'thumbnail'    => 'nullable|url',
                    'caption'      => 'nullable|string',
                    'parse_mode'   => 'nullable|tg_parse_mode',
                    'reply_markup' => 'nullable|tg_reply_markup',
                ],
                'on_prepare' => fn(&$p) => $html_on_prepare($p, 'caption'),
            ],
            'sendMediaGroup' => [
                'params' => [
                    'chat_id' => 'required|int',
                    'media'   => Rule::create(fn(&$v) => is_array($v) && count($v) >= 2 && count($v) <= 10)
                        ->handleError(fn($v) => 'Не передан массив медиа-контента или он некорректен!'),
                ],
                'on_prepare' => function(&$params) {
                    $params['media'] = json_encode(array_map(function($item) {
                        $errors = Rule::get('tg_media_group')->apply($item);
                        if (!empty($errors)) throw new \InvalidArgumentException($errors[0]);
                        if (($item['parse_mode'] ?? '') === 'HTML' && isset($item['caption']))
                            $item['caption'] = self::normalizeHtml($item['caption']);
                        return $item;
                    }, $params['media']));
                },
            ],
            'answerCallbackQuery' => [
                'params' => [
                    'callback_query_id' => 'required|string',
                    'text'              => 'nullable|string',
                ],
            ],
            'setWebhook' => [
                'params' => [
                    'url'        => 'required|secure_url',
                    'show_alert' => 'nullable|bool',
                ],
            ],
            'deleteWebhook' => [],
            'getWebhookInfo' => [],
        ]);
    }

    public function handleMessage(callable $a): void {
        static $offset = 0;

        $response = $this->call('getUpdates', [
            'offset' => $offset + 1
        ]);

        if (!$response['ok']) return;
        
        $last_update_id = $offset;

        $updates = $response['result'] ?? [];

        foreach ($updates as $update) {
            try {
                if (!$a($update)) break;
                
                $last_update_id = $update['update_id'];

            } catch (\Throwable $th) { break; }
        }

        $offset = $last_update_id;
    }

}