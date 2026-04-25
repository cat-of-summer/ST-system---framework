<?php

namespace ST_system\API\Drivers\Bots;

use \ST_system\API\IntegrationDriver;
use \ST_system\Rule;

/**
 * Драйвер для работы с API мессенджера Max (https://dev.max.ru/docs-api)
 *
 * Авторизация: заголовок Authorization: <token>
 * Базовый URL: https://platform-api.max.ru
 *
 * Использование:
 *   $bot = MaxBot::create('ваш-токен');
 *   $bot->call('messages', ['user_id' => 123456789, 'text' => 'Привет!']);
 *   $bot->call('messages', ['chat_id' => 987654321, 'text' => '<b>Жирный</b> текст', 'format' => 'html']);
 */
final class MaxBot extends IntegrationDriver {

    use \ST_system\API\Drivers\Traits\HasHTMLRules;

    /**
     * Таблица обработки HTML-узлов для normalize_html().
     *
     * Поддерживаемые тегом Max HTML-форматирования:
     *   <b>, <strong>  — жирный
     *   <i>, <em>      — курсив
     *   <u>, <ins>     — подчёркнутый
     *   <s>, <del>     — зачёркнутый
     *   <code>, <pre>  — моноширинный
     *   <a href="..."> — ссылка; для упоминания: href="max://user/{user_id}"
     *
     * Остальные теги либо заменяются на переносы строк (блочные),
     * либо удаляются полностью (медиа, формы, таблицы).
     * Теги не из карты — сворачиваются (тег удаляется, содержимое сохраняется).
     */
    protected static function get_nodes_map(): array {

        $line_break = fn($content) => "$content\n";

        return [
            // Void-элемент — перенос строки
            'br'     => "\n",

            // Блочные теги — сохранять содержимое с переносом строки
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

            // Теги, полностью запрещённые — удалить вместе с содержимым
            'form'   => false,
            'img'    => false,

            // Таблицы — теги убираем, текстовое содержимое сохраняем
            'table'  => fn($content) => trim($content) !== '' ? "$content\n" : '',
            'tbody'  => fn($content) => $content,
            'thead'  => fn($content) => $content,
            'tfoot'  => fn($content) => $content,
            'tr'     => fn($content) => trim($content) !== '' ? trim($content) . "\n" : '',
            'td'     => fn($content) => trim($content) !== '' ? trim($content) . ' ' : '',
            'th'     => fn($content) => trim($content) !== '' ? '<b>' . trim($content) . '</b> ' : '',

            // Поддерживаемые форматирующие теги Max HTML — пропускать как есть
            'b'      => true,   // жирный
            'strong' => true,   // жирный (alias)
            'i'      => true,   // курсив
            'em'     => true,   // курсив (alias)
            'u'      => true,   // подчёркнутый
            'ins'    => true,   // подчёркнутый (alias)
            's'      => true,   // зачёркнутый
            'del'    => true,   // зачёркнутый (alias)
            'code'   => true,   // моноширинный (inline)
            'pre'    => true,   // моноширинный (блок)
            'a'      => true,   // ссылка / упоминание пользователя
        ];

    }

    protected static array $CONFIG = ['endpoint' => 'https://platform-api.max.ru'];

    private string $token;

    protected function __init(): void {

        $this->on('__construct', function(string $token) {
            $this->token = $token;
        });

        $this->on('before_call', function($method) {
            if (empty($this->token))
                throw new \Exception("Для доступа методу '{$method}' необходим авторизационный токен!");
        });

        /**
         * Переносим query-параметры (user_id, chat_id, disable_link_preview) из тела запроса
         * в строку URL, так как POST /messages принимает их через query string, а тело — через JSON.
         */
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
            // Убираем null-значения, чтобы они не попадали в JSON-тело
            $params = array_filter($params, fn($v) => $v !== null);
            // Авторизация через заголовок (query-параметр официально не поддерживается)
            $config['headers']['Authorization'] = $this->token;
        });

        $this->registerMethodsMap([

            /**
             * POST /messages — отправить сообщение
             *
             * Query-параметры (передаются в URL):
             *   user_id              — ID пользователя (user_id или chat_id обязателен)
             *   chat_id              — ID чата         (user_id или chat_id обязателен)
             *   disable_link_preview — отключить превью ссылок
             *
             * Тело запроса (JSON):
             *   text        — текст сообщения до 4000 символов; при format=html теги нормализуются
             *   format      — формат разметки: "markdown" | "html"
             *   notify      — уведомлять участников (по умолчанию true)
             *   attachments — массив вложений (например, inline_keyboard с кнопками)
             *   link        — ссылка на другое сообщение
             */
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
                        $params['text'] = self::normalize_html($params['text']);
                },
            ],

        ]);

    }

}
