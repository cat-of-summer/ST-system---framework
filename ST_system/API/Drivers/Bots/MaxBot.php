<?php

namespace ST_system\API\Drivers;

use \ST_system\API\IntegrationDriver;

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

    protected const DEFAULT_POINT = 'https://platform-api.max.ru';

    private string $token;

    protected function __init() {

        static::register_rules_map([
            // Формат разметки текста: markdown или html
            'format'  => [null,  fn($v) => in_array($v, [null, 'markdown', 'html'])],
            '*format' => [
                fn($k) => new \Exception("Некорректный формат форматирования! Допустимые значения: markdown, html"),
                fn($v) => in_array($v, ['markdown', 'html']),
            ],
            // Текстовое поле с конвертацией HTML при format=html
            'html'  => array_merge(static::rule('string'),  ['after' => fn($v, $k, $p) => ($p['format'] ?? '') === 'html' ? self::normalize_html($v) : $v]),
            '*html' => array_merge(static::rule('*string'), ['after' => fn($v, $k, $p) => ($p['format'] ?? '') === 'html' ? self::normalize_html($v) : $v]),
        ]);

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
        $this->on('build_url', function(&$request_url, $point, $method, &$params) {
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

        $this->register_methods_map([

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
                    'user_id'              => [null, fn($v) => is_int($v) && $v > 0],
                    'chat_id'              => [null, fn($v) => is_int($v) && $v > 0],
                    'disable_link_preview' => 'bool',
                    'format'               => 'format',
                    'text'                 => 'html',
                    'notify'               => 'bool',
                    'attachments'          => [null, fn($v) => is_array($v)],
                    'link'                 => [null, fn($v) => is_array($v)],
                ],
                'on_prepare' => function(&$params) {
                    if (empty($params['user_id']) && empty($params['chat_id']))
                        throw new \Exception("Необходимо передать user_id или chat_id для отправки сообщения!");

                    if (!isset($params['text']) && empty($params['attachments']))
                        throw new \Exception("Необходимо передать текст сообщения (text) или вложения (attachments)!");

                    if (isset($params['text']) && mb_strlen($params['text']) > 4000)
                        throw new \Exception("Текст сообщения не должен превышать 4000 символов!");
                },
            ],

        ]);

    }

}
