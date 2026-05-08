<?php

namespace ST_system\API\Drivers\Bots;

use ST_system\API\IntegrationDriver;
use ST_system\Rule;
use ST_system\API\Drivers\Traits\HasHTMLRules;


final class MaxBot extends IntegrationDriver {

    use HasHTMLRules;

    
    protected static function getHtmlRules(): array {

        $line_break = fn($content) => "$content\n";

        return [
            
            'br'     => "\n",

            
            'p'      => $line_break,
            'div'    => $line_break,
            'h1'     => fn($content) => $line_break("<b>$content</b>"),
            'h2'     => fn($content) => $line_break("<b>$content</b>"),
            'h3'     => $line_break,
            'h4'     => $line_break,
            'h5'     => $line_break,
            'h6'     => $line_break,
            'li'     => fn($content) => "— $content\n",
            'dt'     => fn($content) => "• $content: ",
            'dd'     => $line_break,

            
            'form'   => false,
            'img'    => false,

            
            'table'  => fn($content) => trim($content) !== '' ? "$content\n" : '',
            'tbody'  => fn($content) => $content,
            'thead'  => fn($content) => $content,
            'tfoot'  => fn($content) => $content,
            'tr'     => fn($content) => trim($content) !== '' ? trim($content) . "\n" : '',
            'td'     => fn($content) => trim($content) !== '' ? trim($content) . ' ' : '',
            'th'     => fn($content) => trim($content) !== '' ? '<b>' . trim($content) . '</b> ' : '',

            
            'b'      => true,   
            'strong' => true,   
            'i'      => true,   
            'em'     => true,   
            'u'      => true,   
            'ins'    => true,   
            's'      => true,   
            'del'    => true,   
            'code'   => true,   
            'pre'    => true,   
            'a'      => true,   
        ];

    }

    protected static function getDefaultConfig(): array { return ['endpoint' => 'https://platform-api.max.ru']; }

    private string $token;

    protected function __init(): void {

        $this->on('__construct', function(string $token) {
            $this->token = $token;
        });

        $this->on('before_call', function($method) {
            if (empty($this->token))
                throw new \Exception("Для доступа методу '{$method}' необходим авторизационный токен!");
        });

        
        $this->on('build_url', function(&$request_url, $endpoint, $method, &$params) {
            $query = [];
            foreach (['user_id', 'chat_id', 'disable_link_preview'] as $key) {
                if (array_key_exists($key, $params)) {
                    if ($params[$key] !== null)
                        $query[$key] = $params[$key];
                    unset($params[$key]);
                }
            }
            if (!empty($query))
                $request_url .= '?' . http_build_query($query);
        });

        $this->on('before_curl_init', function($request_url, $method, &$params, &$config) {
            
            $params = array_filter($params, fn($v) => $v !== null);
            
            $config['headers']['Authorization'] = $this->token;
        });

        $this->registerMethodsMap([

            
            'messages' => [
                'method'       => 'POST',
                'content_type' => 'application/json',
                'params'       => [
                    'user_id'              => Rule::create(fn(&$v) => $v === null || (is_int($v) && $v > 0))
                        ->handleError(fn($v) => 'user_id должен быть положительным целым числом'),
                    'chat_id'              => Rule::create(fn(&$v) => $v === null || (is_int($v) && $v > 0))
                        ->handleError(fn($v) => 'chat_id должен быть положительным целым числом'),
                    'disable_link_preview' => 'nullable|bool',
                    'format'               => Rule::create(fn(&$v) => $v === null || in_array($v, ['markdown', 'html'], true))
                        ->handleError(fn($v) => 'Некорректный формат форматирования! Допустимые значения: markdown, html'),
                    'text'                 => 'nullable|string',
                    'notify'               => 'nullable|bool',
                    'attachments'          => Rule::create(fn(&$v) => $v === null || is_array($v))
                        ->handleError(fn($v) => 'attachments должен быть массивом'),
                    'link'                 => Rule::create(fn(&$v) => $v === null || is_array($v))
                        ->handleError(fn($v) => 'link должен быть массивом'),
                ],
                'on_prepare' => function(&$params) {
                    if (empty($params['user_id']) && empty($params['chat_id']))
                        throw new \Exception("Необходимо передать user_id или chat_id для отправки сообщения!");

                    if (!isset($params['text']) && empty($params['attachments']))
                        throw new \Exception("Необходимо передать текст сообщения (text) или вложения (attachments)!");

                    if (isset($params['text']) && mb_strlen($params['text']) > 4000)
                        throw new \Exception("Текст сообщения не должен превышать 4000 символов!");

                    if (($params['format'] ?? '') === 'html' && isset($params['text']))
                        $params['text'] = self::normalizeHtml($params['text']);
                },
            ],

        ]);

    }

}
