<?php

namespace ST_system\API\Drivers;

use \ST_system\API\IntegrationDriver;
use \ST_system\Rule;

/**
 * Р”СЂР°Р№РІРµСЂ РґР»СЏ СЂР°Р±РѕС‚С‹ СЃ API РјРµСЃСЃРµРЅРґР¶РµСЂР° Max (https://dev.max.ru/docs-api)
 *
 * РђРІС‚РѕСЂРёР·Р°С†РёСЏ: Р·Р°РіРѕР»РѕРІРѕРє Authorization: <token>
 * Р‘Р°Р·РѕРІС‹Р№ URL: https://platform-api.max.ru
 *
 * РСЃРїРѕР»СЊР·РѕРІР°РЅРёРµ:
 *   $bot = MaxBot::create('РІР°С€-С‚РѕРєРµРЅ');
 *   $bot->call('messages', ['user_id' => 123456789, 'text' => 'РџСЂРёРІРµС‚!']);
 *   $bot->call('messages', ['chat_id' => 987654321, 'text' => '<b>Р–РёСЂРЅС‹Р№</b> С‚РµРєСЃС‚', 'format' => 'html']);
 */
final class MaxBot extends IntegrationDriver {

    use \ST_system\API\Drivers\Traits\HasHTMLRules;

    /**
     * РўР°Р±Р»РёС†Р° РѕР±СЂР°Р±РѕС‚РєРё HTML-СѓР·Р»РѕРІ РґР»СЏ normalize_html().
     *
     * РџРѕРґРґРµСЂР¶РёРІР°РµРјС‹Рµ С‚РµРіРѕРј Max HTML-С„РѕСЂРјР°С‚РёСЂРѕРІР°РЅРёСЏ:
     *   <b>, <strong>  вЂ” Р¶РёСЂРЅС‹Р№
     *   <i>, <em>      вЂ” РєСѓСЂСЃРёРІ
     *   <u>, <ins>     вЂ” РїРѕРґС‡С‘СЂРєРЅСѓС‚С‹Р№
     *   <s>, <del>     вЂ” Р·Р°С‡С‘СЂРєРЅСѓС‚С‹Р№
     *   <code>, <pre>  вЂ” РјРѕРЅРѕС€РёСЂРёРЅРЅС‹Р№
     *   <a href="..."> вЂ” СЃСЃС‹Р»РєР°; РґР»СЏ СѓРїРѕРјРёРЅР°РЅРёСЏ: href="max://user/{user_id}"
     *
     * РћСЃС‚Р°Р»СЊРЅС‹Рµ С‚РµРіРё Р»РёР±Рѕ Р·Р°РјРµРЅСЏСЋС‚СЃСЏ РЅР° РїРµСЂРµРЅРѕСЃС‹ СЃС‚СЂРѕРє (Р±Р»РѕС‡РЅС‹Рµ),
     * Р»РёР±Рѕ СѓРґР°Р»СЏСЋС‚СЃСЏ РїРѕР»РЅРѕСЃС‚СЊСЋ (РјРµРґРёР°, С„РѕСЂРјС‹, С‚Р°Р±Р»РёС†С‹).
     * РўРµРіРё РЅРµ РёР· РєР°СЂС‚С‹ вЂ” СЃРІРѕСЂР°С‡РёРІР°СЋС‚СЃСЏ (С‚РµРі СѓРґР°Р»СЏРµС‚СЃСЏ, СЃРѕРґРµСЂР¶РёРјРѕРµ СЃРѕС…СЂР°РЅСЏРµС‚СЃСЏ).
     */
    protected static function get_nodes_map(): array {

        $line_break = fn($content) => "$content\n";

        return [
            // Void-СЌР»РµРјРµРЅС‚ вЂ” РїРµСЂРµРЅРѕСЃ СЃС‚СЂРѕРєРё
            'br'     => "\n",

            // Р‘Р»РѕС‡РЅС‹Рµ С‚РµРіРё вЂ” СЃРѕС…СЂР°РЅСЏС‚СЊ СЃРѕРґРµСЂР¶РёРјРѕРµ СЃ РїРµСЂРµРЅРѕСЃРѕРј СЃС‚СЂРѕРєРё
            'p'      => $line_break,
            'div'    => $line_break,
            'h1'     => fn($content) => $line_break("<b>$content</b>"),
            'h2'     => fn($content) => $line_break("<b>$content</b>"),
            'h3'     => $line_break,
            'h4'     => $line_break,
            'h5'     => $line_break,
            'h6'     => $line_break,
            'li'     => fn($content) => "вЂ” $content\n",
            'dt'     => fn($content) => "вЂў $content: ",
            'dd'     => $line_break,

            // РўРµРіРё, РїРѕР»РЅРѕСЃС‚СЊСЋ Р·Р°РїСЂРµС‰С‘РЅРЅС‹Рµ вЂ” СѓРґР°Р»РёС‚СЊ РІРјРµСЃС‚Рµ СЃ СЃРѕРґРµСЂР¶РёРјС‹Рј
            'form'   => false,
            'img'    => false,

            // РўР°Р±Р»РёС†С‹ вЂ” С‚РµРіРё СѓР±РёСЂР°РµРј, С‚РµРєСЃС‚РѕРІРѕРµ СЃРѕРґРµСЂР¶РёРјРѕРµ СЃРѕС…СЂР°РЅСЏРµРј
            'table'  => fn($content) => trim($content) !== '' ? "$content\n" : '',
            'tbody'  => fn($content) => $content,
            'thead'  => fn($content) => $content,
            'tfoot'  => fn($content) => $content,
            'tr'     => fn($content) => trim($content) !== '' ? trim($content) . "\n" : '',
            'td'     => fn($content) => trim($content) !== '' ? trim($content) . ' ' : '',
            'th'     => fn($content) => trim($content) !== '' ? '<b>' . trim($content) . '</b> ' : '',

            // РџРѕРґРґРµСЂР¶РёРІР°РµРјС‹Рµ С„РѕСЂРјР°С‚РёСЂСѓСЋС‰РёРµ С‚РµРіРё Max HTML вЂ” РїСЂРѕРїСѓСЃРєР°С‚СЊ РєР°Рє РµСЃС‚СЊ
            'b'      => true,   // Р¶РёСЂРЅС‹Р№
            'strong' => true,   // Р¶РёСЂРЅС‹Р№ (alias)
            'i'      => true,   // РєСѓСЂСЃРёРІ
            'em'     => true,   // РєСѓСЂСЃРёРІ (alias)
            'u'      => true,   // РїРѕРґС‡С‘СЂРєРЅСѓС‚С‹Р№
            'ins'    => true,   // РїРѕРґС‡С‘СЂРєРЅСѓС‚С‹Р№ (alias)
            's'      => true,   // Р·Р°С‡С‘СЂРєРЅСѓС‚С‹Р№
            'del'    => true,   // Р·Р°С‡С‘СЂРєРЅСѓС‚С‹Р№ (alias)
            'code'   => true,   // РјРѕРЅРѕС€РёСЂРёРЅРЅС‹Р№ (inline)
            'pre'    => true,   // РјРѕРЅРѕС€РёСЂРёРЅРЅС‹Р№ (Р±Р»РѕРє)
            'a'      => true,   // СЃСЃС‹Р»РєР° / СѓРїРѕРјРёРЅР°РЅРёРµ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ
        ];

    }

    protected const DEFAULT_ENDPOINT = 'https://platform-api.max.ru';

    private string $token;

    protected function __init(): void {

        $this->on('__construct', function(string $token) {
            $this->token = $token;
        });

        $this->on('before_call', function($method) {
            if (empty($this->token))
                throw new \Exception("Р”Р»СЏ РґРѕСЃС‚СѓРїР° РјРµС‚РѕРґСѓ '{$method}' РЅРµРѕР±С…РѕРґРёРј Р°РІС‚РѕСЂРёР·Р°С†РёРѕРЅРЅС‹Р№ С‚РѕРєРµРЅ!");
        });

        /**
         * РџРµСЂРµРЅРѕСЃРёРј query-РїР°СЂР°РјРµС‚СЂС‹ (user_id, chat_id, disable_link_preview) РёР· С‚РµР»Р° Р·Р°РїСЂРѕСЃР°
         * РІ СЃС‚СЂРѕРєСѓ URL, С‚Р°Рє РєР°Рє POST /messages РїСЂРёРЅРёРјР°РµС‚ РёС… С‡РµСЂРµР· query string, Р° С‚РµР»Рѕ вЂ” С‡РµСЂРµР· JSON.
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

        $this->register_methods_map([

            /**
             * POST /messages вЂ” РѕС‚РїСЂР°РІРёС‚СЊ СЃРѕРѕР±С‰РµРЅРёРµ
             *
             * Query-РїР°СЂР°РјРµС‚СЂС‹ (РїРµСЂРµРґР°СЋС‚СЃСЏ РІ URL):
             *   user_id              вЂ” ID РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ (user_id РёР»Рё chat_id РѕР±СЏР·Р°С‚РµР»РµРЅ)
             *   chat_id              вЂ” ID С‡Р°С‚Р°         (user_id РёР»Рё chat_id РѕР±СЏР·Р°С‚РµР»РµРЅ)
             *   disable_link_preview вЂ” РѕС‚РєР»СЋС‡РёС‚СЊ РїСЂРµРІСЊСЋ СЃСЃС‹Р»РѕРє
             *
             * РўРµР»Рѕ Р·Р°РїСЂРѕСЃР° (JSON):
             *   text        вЂ” С‚РµРєСЃС‚ СЃРѕРѕР±С‰РµРЅРёСЏ РґРѕ 4000 СЃРёРјРІРѕР»РѕРІ; РїСЂРё format=html С‚РµРіРё РЅРѕСЂРјР°Р»РёР·СѓСЋС‚СЃСЏ
             *   format      вЂ” С„РѕСЂРјР°С‚ СЂР°Р·РјРµС‚РєРё: "markdown" | "html"
             *   notify      вЂ” СѓРІРµРґРѕРјР»СЏС‚СЊ СѓС‡Р°СЃС‚РЅРёРєРѕРІ (РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ true)
             *   attachments вЂ” РјР°СЃСЃРёРІ РІР»РѕР¶РµРЅРёР№ (РЅР°РїСЂРёРјРµСЂ, inline_keyboard СЃ РєРЅРѕРїРєР°РјРё)
             *   link        вЂ” СЃСЃС‹Р»РєР° РЅР° РґСЂСѓРіРѕРµ СЃРѕРѕР±С‰РµРЅРёРµ
             */
            'messages' => [
                'method'       => 'POST',
                'content_type' => 'application/json',
                'params'       => [
                    'user_id'              => Rule::create(fn(&$v) => $v === null || (is_int($v) && $v > 0))
                        ->handleError(fn($v) => 'user_id РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ РїРѕР»РѕР¶РёС‚РµР»СЊРЅС‹Рј С†РµР»С‹Рј С‡РёСЃР»РѕРј'),
                    'chat_id'              => Rule::create(fn(&$v) => $v === null || (is_int($v) && $v > 0))
                        ->handleError(fn($v) => 'chat_id РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ РїРѕР»РѕР¶РёС‚РµР»СЊРЅС‹Рј С†РµР»С‹Рј С‡РёСЃР»РѕРј'),
                    'disable_link_preview' => 'nullable|bool',
                    'format'               => Rule::create(fn(&$v) => $v === null || in_array($v, ['markdown', 'html'], true))
                        ->handleError(fn($v) => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ С„РѕСЂРјР°С‚ С„РѕСЂРјР°С‚РёСЂРѕРІР°РЅРёСЏ! Р”РѕРїСѓСЃС‚РёРјС‹Рµ Р·РЅР°С‡РµРЅРёСЏ: markdown, html'),
                    'text'                 => 'nullable|string',
                    'notify'               => 'nullable|bool',
                    'attachments'          => Rule::create(fn(&$v) => $v === null || is_array($v))
                        ->handleError(fn($v) => 'attachments РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ РјР°СЃСЃРёРІРѕРј'),
                    'link'                 => Rule::create(fn(&$v) => $v === null || is_array($v))
                        ->handleError(fn($v) => 'link РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ РјР°СЃСЃРёРІРѕРј'),
                ],
                'on_prepare' => function(&$params) {
                    if (empty($params['user_id']) && empty($params['chat_id']))
                        throw new \Exception("РќРµРѕР±С…РѕРґРёРјРѕ РїРµСЂРµРґР°С‚СЊ user_id РёР»Рё chat_id РґР»СЏ РѕС‚РїСЂР°РІРєРё СЃРѕРѕР±С‰РµРЅРёСЏ!");

                    if (!isset($params['text']) && empty($params['attachments']))
                        throw new \Exception("РќРµРѕР±С…РѕРґРёРјРѕ РїРµСЂРµРґР°С‚СЊ С‚РµРєСЃС‚ СЃРѕРѕР±С‰РµРЅРёСЏ (text) РёР»Рё РІР»РѕР¶РµРЅРёСЏ (attachments)!");

                    if (isset($params['text']) && mb_strlen($params['text']) > 4000)
                        throw new \Exception("РўРµРєСЃС‚ СЃРѕРѕР±С‰РµРЅРёСЏ РЅРµ РґРѕР»Р¶РµРЅ РїСЂРµРІС‹С€Р°С‚СЊ 4000 СЃРёРјРІРѕР»РѕРІ!");

                    if (($params['format'] ?? '') === 'html' && isset($params['text']))
                        $params['text'] = self::normalize_html($params['text']);
                },
            ],

        ]);

    }

}
