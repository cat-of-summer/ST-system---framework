<?php

namespace ST_system;

class Telegram_bot {
    private static $POINT = 'https://api.telegram.org/bot';

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
    private $command_handlers = [];

    public function __construct($token) {
        $this->token = $token;
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

        if ($code != 200)
            throw new \Exception("Ошибка HTTP: {$code}. {$response}");
        
        $response_data = @json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) 
            throw new \Exception("Ошибка при декодировании JSON: ".json_last_error_msg());
            
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
            'text' => $text
        ]];

        return $this->send_request('sendMessage', $params);
    }

    public function get_updates(array $params = []) {
        $params = self::prepare_params([
            'offset' => [null, fn($v) => is_int($v)],
            'limit' => [100, fn($v) => is_int($v) && $v >= 1 && $v <= 100],
            'timeout' => [0, fn($v) => is_int($v) && $v >= 0],
            'allowed_updates' => [null, fn($v) => is_array($v)],
        ], $params);
    
        $response = $this->send_request('getUpdates', $params);

        if (!$response['ok'])
            throw new \Exception("Ошибка получения обновлений:".PHP_EOL.json_encode($response, JSON_PRETTY_PRINT));

        return $response['result'];
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