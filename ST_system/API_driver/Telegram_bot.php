<?php

namespace ST_system;

class Telegram_bot {
    private static $POINT = 'https://api.telegram.org/bot';

    public const ERROR_CODES = [
        'Unauthorized'                                        => 1,
        'Bad Request: chat not found'                         => 2,
        'Forbidden: bot was blocked by the user'              => 3,
        'Forbidden: bot can\'t send messages to the user'     => 4,
        'Bad Request: message text is empty'                  => 5,
        'Bad Request: message is too long'                    => 6,
        'Too Many Requests: retry after'                      => 7,
        'Not Found: method not found'                         => 8,
        'Bad Request: message to delete not found'            => 9,
        'Bad Request: message to edit not found'              => 10,
        'Internal Server Error'                               => 11,
		'Bad Request: message is not modified'                => 12,
    ];

    private static array $nodes_map = [
        'b'           => 'b',
        'strong'      => 'strong',
        'i'           => 'i',
        'em'          => 'em',
        'u'           => 'u',
        's'           => 's',
        'strike'      => 's',
        'del'         => 's',
        'ins'         => 'ins',
        'a'           => 'a',
        'code'        => 'code',
        'pre'         => 'pre',
        'br'          => "\n",
        'p'           => "\n\n",
        'div'         => "\n\n",
        'h1'          => 'b',
        'h2'          => 'b',
        'h3'          => 'b',
        'h4'          => 'b',
        'h5'          => 'b',
        'h6'          => 'b',
        'li'          => '• ',
    ];

    private static function prepare_params(array $config, array $input) {
        $result = [];
    
        foreach ($config as $key => [$default, $rule]) {
            $value = isset($input[$key]) ? $input[$key] : $default;
    
            $result[$key] = call_user_func($rule, $input[$key])
                ? $value
                : $default;
        }
    
        return $result;
    }
    
    private $token;
    private $base_url;
    private $command_handlers = [];

    public function __construct(array $PARAMS = []) {
        $this->token = $PARAMS['token'];

        $this->base_url = isset($PARAMS['base_url'])
            ? $PARAMS['base_url']
            : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'];
    }

    private function normalize_html(string $html) {

        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $output = '';

        $walker = function (\DOMNodeList $nodes) use (&$walker, &$output) {
            foreach ($nodes as $node) {
                if ($node->nodeType === XML_TEXT_NODE) {
                    // 1) собрать все пробельные символы в один пробел
                    $text = preg_replace('/\s+/u', ' ', $node->textContent);
                    // 2) если после обрезки крайних пробелов ничего не осталось — пропускаем
                    if (trim($text) === '') {
                        continue;
                    }
                    // 3) оставляем внутренние пробелы, но не экранируем их
                    //    htmlspecialchars без ENT_NOQUOTES не трогает пробелы
                    $output .= htmlspecialchars($text, ENT_NOQUOTES);
                    continue;
                }

                if ($node->nodeType !== XML_ELEMENT_NODE) {
                    continue;
                }

                $tag = mb_strtolower($node->nodeName);
                if (!array_key_exists($tag, self::$nodes_map)) {
                    // просто рекурсивно идём внутрь, без тега-обёртки
                    $walker($node->childNodes);
                    continue;
                }

                $map = self::$nodes_map[$tag];

                // 1) открытие
                if ($map === "\n" || $map === "\n\n" || $map === '• ') {
                    // специальные «теги», вставляем текст
                    $output .= $map;
                } elseif (in_array($map, ['b','strong','i','em','u','s','ins','code','pre','a'], true)) {
                    // реальные теги
                    if ($map === 'a') {
                        $href = $node->getAttribute('href');
                        // корректируем относительные ссылки
                        if ($href && strpos($href, 'http') !== 0 && strpos($href, '//') === false) {
                            $href = rtrim($this->base_url, '/').'/'.ltrim($href, '/');
                        }
                        $output .= '<a href="'.htmlspecialchars($href, ENT_QUOTES).'">';
                    } else {
                        $output .= "<{$map}>";
                    }
                }

                // 2) дети
                if ($map === 'pre') {
                    // preserve whitespace inside <pre>
                    $output .= htmlspecialchars(trim($node->textContent), ENT_NOQUOTES);
                } else {
                    $walker($node->childNodes);
                }

                // 3) закрытие
                if (in_array($map, ['b','strong','i','em','u','s','ins','code','pre'], true)) {
                    $output .= "</{$map}>";
                } elseif ($map === 'a') {
                    $output .= '</a>';
                }
            }
        };

        $walker($doc->childNodes);

        // Убираем ведущие/хвостовые переносы и возвращаем
        return trim($output);
    }

    private function send_request(string $method, array $params = []) {
        $request = curl_init(self::$POINT.$this->token.'/'.$method);

        curl_setopt($request, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($request, CURLOPT_POST, true);
        curl_setopt($request, CURLOPT_POSTFIELDS, http_build_query($params));

        $response = curl_exec($request);
        $error = curl_error($request);
        $code = curl_getinfo($request, CURLINFO_HTTP_CODE);

        curl_close($request);

        if ($error)
            throw new \Exception("Ошибка при запросе к API: {$error}");
        
        $response_data = @json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) 
            throw new \Exception("Ошибка при декодировании JSON: ".json_last_error_msg());
        
        if ($code != 200) {
            $error_code = $response_data['description'] ?? null;
            throw new \RuntimeException("Telegram {$method} error: {$error_code}", isset(self::ERROR_CODES[$error_code]) ? self::ERROR_CODES[$error_code] : 0);
        }

        return $response_data;
    }

    public function send_message(string $chat_id, string $text, array $params = []) {

        $params = [...self::prepare_params([
            'parse_mode' => [null, fn($v) => in_array($v, [null, 'HTML', 'Markdown', 'MarkdownV2'])],
            'disable_web_page_preview' => [false, fn($v) => is_bool($v)],
            'reply_to_message_id' => [null, fn($v) => is_null($v) || is_int($v)],
            'reply_markup' => [null, fn($v) => is_array($v)],
        ], $params), ...[
            'chat_id' => $chat_id,
        ]];

        $params['text'] = ($params['parse_mode'] ?? null) == 'HTML'
            ? $this->normalize_html($text)
            : $text;

        return $this->send_request('sendMessage', $params);
    }

    public function delete_message(string $chat_id, string $message_id) {
        $params = [
            'chat_id'    => $chat_id,
            'message_id' => $message_id,
        ];

        return $this->send_request('deleteMessage', $params);
    }

    public function edit_message(string $chat_id, string $message_id, string $text, array $params = []) {

        $params = [...self::prepare_params([
            'parse_mode' => [null, fn($v) => in_array($v, [null, 'HTML', 'Markdown', 'MarkdownV2'])],
            'disable_web_page_preview' => [false, fn($v) => is_bool($v)],
            'reply_to_message_id' => [null, fn($v) => is_null($v) || is_int($v)],
            'reply_markup' => [null, fn($v) => is_array($v)],
        ], $params), ...[
            'chat_id' => $chat_id,
            'message_id' => $message_id
        ]];

        $params['text'] = ($params['parse_mode'] ?? null) == 'HTML'
            ? $this->normalize_html($text)
            : $text;

        return $this->send_request('editMessageText', $params);
    }

    public function get_updates(array $params = []) {
        $params = self::prepare_params([
            'offset' => [null, fn($v) => is_int($v)],
            'limit' => [100, fn($v) => is_int($v) && $v >= 1 && $v <= 100],
            'timeout' => [0, fn($v) => is_int($v) && $v >= 0],
            'allowed_updates' => [null, fn($v) => is_array($v)],
        ], $params);

        return $this->send_request('getUpdates', $params);
    }
    
    public function set_command(string $command, callable $handler) {
        $this->command_handlers[$command] = $handler;
    }

    public function set_command_map(array $commands) {
        array_walk($commands, [$this, 'set_command']);
    }

    public function run_command(array $update) {
        if (
            !isset($update['message']['text'])
            || strpos($update['message']['text'], '/') !== 0
        ) return false;

        return isset($this->command_handlers[$update['message']['text']])
            ? call_user_func($this->command_handlers[$update['message']['text']], $update)
            : false;
    }

    public function set_webhook(array $params = ['url' => '']) {
        $params = self::prepare_params([
            'url' => ['', fn($v) => is_string($v)],
        ], $params);

        $this->send_request('setWebhook', $params);
    }

    public function delete_webhook() {
        $this->send_request('deleteWebhook');
    }
}