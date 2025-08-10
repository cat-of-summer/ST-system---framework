<?php

namespace ST_system\API;

abstract class Integration_driver {

    protected const ENABLE_TOKEN_CACHE = false;
    protected const TOKENS_DIR = '/';

    protected const DEFAULT_POINT = '';
        
    private array $listeners = [];
    private array $methods_map = [];

    final public function on(string $event, callable $listener) {
        $this->listeners[$event][] = $listener;
    }

    private function trigger(string $event, &...$params) {
        if (empty($this->listeners[$event]))
            return false;

        foreach ($this->listeners[$event] as $listener)
            call_user_func_array($listener, $params);
    }

    final protected static function prepare_params(array $config, &$input, $on_prepare = null) {

        $is_scalar = !is_array($input);
        $values   = $is_scalar ? [0 => $input] : $input;
        $rules    = $is_scalar ? [0 => $config] : $config;
        $result   = [];

        foreach ($rules as $key => $rule) {
            [$default, $rule, $convert] = array_pad($rule, 3, null);

            $val = $values[$key] ?? $default;

            if (is_callable($rule) && !call_user_func($rule, $val, $result))
                $val = $default;
            
            if ($val instanceof \Throwable)
                throw $val;

            if ($val === null)
                continue;
            
            if (is_callable($convert) && ($v = call_user_func($convert, $val, $result)))
                $val = $v;
                        
            $result[$key] = $val;
        }

        $input = $is_scalar ? $result[0] : $result;

        if (is_callable($on_prepare) && ($v = call_user_func($on_prepare, $input)))
            $input = $v;
        
        return $input;
    }

    protected function __init() {}

    final public function __construct(...$args) {
        $this->__init();

        $this->trigger('__construct', ...$args);

        if (empty(static::DEFAULT_POINT) || !filter_var(static::DEFAULT_POINT, FILTER_VALIDATE_URL))
            throw new \Exception("Не задана точка API для ".get_called_class());

        if (static::ENABLE_TOKEN_CACHE && !is_dir(static::TOKENS_DIR))
            throw new \Exception("Не удалось найти директорию для токенов: ".static::TOKENS_DIR);
    }

    final public function save_token(string $token, array $params = []) {
        if (!static::ENABLE_TOKEN_CACHE) 
            return null;

        $params['expires_in'] = isset($params['expires_in']) ? (int)$params['expires_in'] : -1;
        $params['token_name'] = isset($params['token_name']) ? (string)$params['token_name'] : 'default';

        $file_path = str_replace('//', '/', static::TOKENS_DIR.'/'.md5(json_encode(array_diff_key($params, array_flip(['expires_in', 'token_name'])))).'.'.$params['token_name']);

        file_put_contents($file_path, json_encode([
            'token' => $token,
            'expires_in' => $params['expires_in'],
            'created_at' => time(),
        ]));
    }

    final public function load_token(array $params = []) {
        if (!static::ENABLE_TOKEN_CACHE) 
            return null;

        $params['token_name'] = isset($params['token_name']) ? (string)$params['token_name'] : 'default';

        $file_path = str_replace('//', '/', static::TOKENS_DIR.'/'.md5(json_encode(array_diff_key($params, array_flip(['expires_in', 'token_name'])))).'.'.$params['token_name']);

        if (!file_exists($file_path))
            return null;

        $data = @json_decode(file_get_contents($file_path), true);

        if (json_last_error() !== JSON_ERROR_NONE)
            throw new \Exception("Ошибка при декодировании токена: '".json_last_error_msg()."' в ".get_called_class());

        if (isset($data['expires_in']) && $data['expires_in'] > -1 && (time() - $data['created_at']) > $data['expires_in']) {
            unlink($file_path);
            return false;
        }

        return $data['token'];
    }

    final protected function method_config(string $method) {
        return $this->methods_map[$method] ?? [];
    }

    final public function register_method(string $method, $config = []) {
        if (isset($this->methods_map[$method]))
            throw new \Exception("Метод '{$method}' уже зарегистрирован в ".get_called_class());

        if (!is_array($config) && !is_callable($config)) {
            throw new \Exception("Конфигурация метода '{$method}' должна быть массивом или функцией в ".get_called_class());
        }

        switch (true) {
            case is_callable($config):
                break;
            case is_array($config):
                self::prepare_params([
                    'point' => [static::DEFAULT_POINT, fn($value) => !empty($value) && filter_var($value, FILTER_VALIDATE_URL)],
                    'method' => ['GET', fn($value) => in_array(strtoupper($value), ['GET', 'POST']), fn($value) => strtoupper($value)],
                    'params' => [[], fn($value) => is_array($value)],
                    'meta' => [[]],
                ], $config);
                break;
            default:
                throw new \Exception("Неверный формат конфигурации метода '{$method}' в ".get_called_class());
        }

        $this->methods_map[$method] = $config;
    }

    final public function register_methods_map(array $methods) {
        array_walk($methods, fn($config, $method) => $this->register_method($method, $config));
    }

    final protected function curl_init($request_url, $request_method, array $params = []) {
        $curl = curl_init();

        $this->trigger('before_curl_init', $request_url, $request_method, $params);

        switch ($request_method) {
            case 'GET':
                $request_url .= '?'.http_build_query($params);
                break;
            case 'POST':
                curl_setopt_array($curl, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query($params)
                ]);
                break;
            default:
                throw new \Exception("Метод запроса '{$request_method}' не поддерживается в ".get_called_class());
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $request_url,
            CURLOPT_RETURNTRANSFER => true
        ]);

        return $curl;
    }

    final protected function build_url(string $method, string $point = '') {
        $point = filter_var($point, FILTER_VALIDATE_URL)
            ? $point
            : (isset($this->methods_map[$method]) ? $this->methods_map[$method]['point'] : static::DEFAULT_POINT);

        $request_url = $point.'/'.$method;
        
        [$p, $u] = preg_match('#^(https?://)#i', $request_url, $matches)
            ? [$matches[1], substr($request_url, strlen($matches[1]))]
            : ['', $request_url];

        return [$p.preg_replace('#/{2,}#', '/', explode('?', $u, 2)[0]), $point];
    }

    final protected function execute_curl($curl) {
        $response_data = [
            'response' => curl_exec($curl),
            'error' => curl_error($curl),
            'http_code' => curl_getinfo($curl, CURLINFO_HTTP_CODE)
        ];

        curl_close($curl);

        return $response_data;
    }

    final public function call(string $method, array $params = []) {
        if (!isset($this->methods_map[$method]))
            throw new \Exception("Метод '{$method}' не зарегистрирован в ".get_called_class());

        $this->trigger('before_call', $method, $params);
        
        if (is_callable($this->methods_map[$method]))
            return call_user_func($this->methods_map[$method], $params);
        
        $config = $this->methods_map[$method];

        self::prepare_params($config['params'], $params);

        $this->trigger('call', $method, $params);

        [$request_url, $point] = $this->build_url($method, $config['point']);

        $this->trigger('build_url', $request_url, $point, $method, $params);
    
        $curl = $this->curl_init($request_url, $config['method'], $params);

        $response_data = $this->execute_curl($curl);

        if ($response_data['error']) {

            if ($this->trigger('curl_error', $method, $params, $response_data) === false)
                throw new \Exception("Ошибка при запросе к API: '{$response_data['error']}' в ".get_called_class());

            return false;
        } else {

            if ($this->trigger('prepare_response', $method, $params, $response_data) === false) {
                $response_data['response'] = @json_decode($response_data['response'], true);

                if (json_last_error() !== JSON_ERROR_NONE) 
                    throw new \Exception("Ошибка при декодировании ответа: '".json_last_error_msg()."' в ".get_called_class());
            }

            $this->trigger('response', $method, $params, $response_data);
            
            return $response_data['response'];
        }
    }

}