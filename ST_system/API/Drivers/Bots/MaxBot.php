<?php

namespace ST_system\API\Drivers;

use \ST_system\API\IntegrationDriver;
use \ST_system\Rule;

/**
 * ������� ��� ������ � API ����������� Max (https://dev.max.ru/docs-api)
 *
 * �����������: ��������� Authorization: <token>
 * ������� URL: https://platform-api.max.ru
 *
 * �������������:
 *   $bot = MaxBot::create('���-�����');
 *   $bot->call('messages', ['user_id' => 123456789, 'text' => '������!']);
 *   $bot->call('messages', ['chat_id' => 987654321, 'text' => '<b>������</b> �����', 'format' => 'html']);
 */
final class MaxBot extends IntegrationDriver {

    use \ST_system\API\Drivers\Traits\HasHTMLRules;

    /**
     * ������� ��������� HTML-����� ��� normalize_html().
     *
     * �������������� ����� Max HTML-��������������:
     *   <b>, <strong>  � ������
     *   <i>, <em>      � ������
     *   <u>, <ins>     � ������������
     *   <s>, <del>     � �����������
     *   <code>, <pre>  � ������������
     *   <a href="..."> � ������; ��� ����������: href="max://user/{user_id}"
     *
     * ��������� ���� ���� ���������� �� �������� ����� (�������),
     * ���� ��������� ��������� (�����, �����, �������).
     * ���� �� �� ����� � ������������� (��� ���������, ���������� �����������).
     */
    protected static function get_nodes_map(): array {

        $line_break = fn($content) => "$content\n";

        return [
            // Void-������� � ������� ������
            'br'     => "\n",

            // ������� ���� � ��������� ���������� � ��������� ������
            'p'      => $line_break,
            'div'    => $line_break,
            'h1'     => fn($content) => $line_break("<b>$content</b>"),
            'h2'     => fn($content) => $line_break("<b>$content</b>"),
            'h3'     => $line_break,
            'h4'     => $line_break,
            'h5'     => $line_break,
            'h6'     => $line_break,
            'li'     => fn($content) => "� $content\n",
            'dt'     => fn($content) => "� $content: ",
            'dd'     => $line_break,

            // ����, ��������� ����������� � ������� ������ � ����������
            'form'   => false,
            'img'    => false,

            // ������� � ���� �������, ��������� ���������� ���������
            'table'  => fn($content) => trim($content) !== '' ? "$content\n" : '',
            'tbody'  => fn($content) => $content,
            'thead'  => fn($content) => $content,
            'tfoot'  => fn($content) => $content,
            'tr'     => fn($content) => trim($content) !== '' ? trim($content) . "\n" : '',
            'td'     => fn($content) => trim($content) !== '' ? trim($content) . ' ' : '',
            'th'     => fn($content) => trim($content) !== '' ? '<b>' . trim($content) . '</b> ' : '',

            // �������������� ������������� ���� Max HTML � ���������� ��� ����
            'b'      => true,   // ������
            'strong' => true,   // ������ (alias)
            'i'      => true,   // ������
            'em'     => true,   // ������ (alias)
            'u'      => true,   // ������������
            'ins'    => true,   // ������������ (alias)
            's'      => true,   // �����������
            'del'    => true,   // ����������� (alias)
            'code'   => true,   // ������������ (inline)
            'pre'    => true,   // ������������ (����)
            'a'      => true,   // ������ / ���������� ������������
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
                throw new \Exception("��� ������� ������ '{$method}' ��������� ��������������� �����!");
        });

        /**
         * ��������� query-��������� (user_id, chat_id, disable_link_preview) �� ���� �������
         * � ������ URL, ��� ��� POST /messages ��������� �� ����� query string, � ���� � ����� JSON.
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
            $params = array_filter($params, fn($v) => $v !== null);
            $config['headers']['Authorization'] = $this->token;
        });

        $this->registerMethodsMap([

            /**
             * POST /messages � ��������� ���������
             *
             * Query-��������� (���������� � URL):
             *   user_id              � ID ������������ (user_id ��� chat_id ����������)
             *   chat_id              � ID ����         (user_id ��� chat_id ����������)
             *   disable_link_preview � ��������� ������ ������
             *
             * ���� ������� (JSON):
             *   text        � ����� ��������� �� 4000 ��������; ��� format=html ���� �������������
             *   format      � ������ ��������: "markdown" | "html"
             *   notify      � ���������� ���������� (�� ��������� true)
             *   attachments � ������ �������� (��������, inline_keyboard � ��������)
             *   link        � ������ �� ������ ���������
             */
            'messages' => [
                'method'       => 'POST',
                'content_type' => 'application/json',
                'params'       => [
                    'user_id'              => Rule::create(fn(&$v) => $v === null || (is_int($v) && $v > 0))
                        ->handleError(fn($v) => 'user_id ������ ���� ������������� ����� ������'),
                    'chat_id'              => Rule::create(fn(&$v) => $v === null || (is_int($v) && $v > 0))
                        ->handleError(fn($v) => 'chat_id ������ ���� ������������� ����� ������'),
                    'disable_link_preview' => 'nullable|bool',
                    'format'               => Rule::create(fn(&$v) => $v === null || in_array($v, ['markdown', 'html'], true))
                        ->handleError(fn($v) => '������������ ������ ��������������! ���������� ��������: markdown, html'),
                    'text'                 => 'nullable|string',
                    'notify'               => 'nullable|bool',
                    'attachments'          => Rule::create(fn(&$v) => $v === null || is_array($v))
                        ->handleError(fn($v) => 'attachments ������ ���� ��������'),
                    'link'                 => Rule::create(fn(&$v) => $v === null || is_array($v))
                        ->handleError(fn($v) => 'link ������ ���� ��������'),
                ],
                'on_prepare' => function(&$params) {
                    if (empty($params['user_id']) && empty($params['chat_id']))
                        throw new \Exception("���������� �������� user_id ��� chat_id ��� �������� ���������!");

                    if (!isset($params['text']) && empty($params['attachments']))
                        throw new \Exception("���������� �������� ����� ��������� (text) ��� �������� (attachments)!");

                    if (isset($params['text']) && mb_strlen($params['text']) > 4000)
                        throw new \Exception("����� ��������� �� ������ ��������� 4000 ��������!");

                    if (($params['format'] ?? '') === 'html' && isset($params['text']))
                        $params['text'] = self::normalize_html($params['text']);
                },
            ],

        ]);

    }

}
