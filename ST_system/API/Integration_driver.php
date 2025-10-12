<?php

namespace ST_system\API;

abstract class Integration_driver {

    protected const DEFAULT_POINT = '';
    protected const CACHE_DIRECTORY = '';
    
    private static $CACHE_DIRECTORY = null;

    private array $listeners = [];
    private array $methods_map = [];
    private array $rules_map = [];

    final protected function on(string $event, callable $listener) {
        $this->listeners[$event][] = $listener;
    }

    private function trigger(string $event, &...$params) {
        if (empty($this->listeners[$event]))
            return false;

        foreach ($this->listeners[$event] as $listener)
            call_user_func_array($listener, $params);
    }

    final public static function create(...$params): self {
        return new static(...$params);
    }

    final protected function prepare_params(array $config, &$input, $on_prepare = null) {

        $is_scalar = !is_array($input);
        $values   = $is_scalar ? [0 => $input] : $input;
        $rules    = $is_scalar ? [0 => $config] : $config;
        $result   = [];

        foreach ($rules as $key => $rule_config) {

            if (is_string($rule_config))
                $rule_config = $this->rule($rule_config);
            
            $default = ($default = $rule_config['default'] ?? $rule_config[0] ?? null) && is_callable($default) 
                ? $default($key, $result) 
                : $default;

            $rule = $rule_config['rule'] ?? $rule_config[1] ?? null;
            $before = $rule_config['before'] ?? $rule_config[2] ?? null;
            $after = $rule_config['after'] ?? $rule_config[3] ?? null;

            $value = $values[$key] ?? $default;

            if (is_callable($before))
                $value = $before($value, $key, $result);

            if (is_callable($rule) && !$rule($value, $key, $result))
                $value = $default;
            
            if ($value instanceof \Throwable)
                throw $value;

            if ($value === null)
                continue;

            if (is_callable($after))
                $value = $after($value, $key, $result);
                                                
            $result[$key] = $value;
        }

        $input = $is_scalar ? $result[0] : $result;

        if (is_callable($on_prepare) && ($v = $on_prepare($input)))
            $input = $v;

        return $input;
    }

    protected function __init() {}

    final public function __construct(...$args) {
        if (static::$CACHE_DIRECTORY === null && is_string(static::CACHE_DIRECTORY) && static::CACHE_DIRECTORY != '') {
            if (strpos(static::CACHE_DIRECTORY, '~') === 0)
                static::$CACHE_DIRECTORY = $_SERVER['DOCUMENT_ROOT'].DIRECTORY_SEPARATOR.trim(static::CACHE_DIRECTORY, DIRECTORY_SEPARATOR.'~');
            elseif (strpos(static::CACHE_DIRECTORY, DIRECTORY_SEPARATOR) !== 0)
                static::$CACHE_DIRECTORY = __DIR__.DIRECTORY_SEPARATOR.trim(static::CACHE_DIRECTORY, DIRECTORY_SEPARATOR);

            if (!is_dir(static::$CACHE_DIRECTORY)) {
                mkdir(static::$CACHE_DIRECTORY, 0775, true);

                if (!is_dir(static::$CACHE_DIRECTORY))
                    static::$CACHE_DIRECTORY = '';
            }
        }

        $this->__init();
        $this->trigger('__construct', ...$args);
    }

    final protected function register_method(string $method, $config = []): self {
        if (isset($this->methods_map[$method]))
            throw new \Exception("Метод '{$method}' уже зарегистрирован в ".get_called_class());

        if (!is_array($config) && !is_callable($config))
            throw new \Exception("Конфигурация метода '{$method}' должна быть массивом или функцией в ".get_called_class());
        
        switch (true) {
            case is_callable($config):
                break;
            case is_array($config):
                $this->prepare_params([
                    'point' => [static::DEFAULT_POINT, fn($value) => !empty($value) && filter_var($value, FILTER_VALIDATE_URL)],
                    'method' => ['GET', fn($value) => in_array(strtoupper($value), ['GET', 'POST']), fn($value) => strtoupper($value)],
                    'params' => [[], fn($value) => is_array($value)],
                    'on_prepare' => [false, fn($value) => is_callable($value)],
                    'cache_ttl' => [0, fn($value) => is_int($value)],
                    'meta' => [[]],
                ], $config);
                break;
            default:
                throw new \Exception("Неверный формат конфигурации метода '{$method}' в ".get_called_class());
        }

        $this->methods_map[$method] = $config;

        return $this;
    }

    final protected function unregister_method(string $method): self {
        unset($this->methods_map[$method]);

        return $this;
    }

    final protected function register_methods_map(array $methods): self {
        array_walk($methods, fn($config, $method) => $this->register_method($method, $config));

        return $this;
    }

    final protected function unregister_methods_map(array $methods): self {
        array_walk($methods, fn($method) => $this->unregister_method($method));

        return $this;
    }

    final protected function register_rule(string $rule, array $config) {
        if (isset($this->rules_map[$rule]))
            throw new \Exception("Правило '{$rule}' уже зарегистрировано в ".get_called_class());

        $this->rules_map[$rule] = $config;
    }

    final protected function register_rules_map(array $rules) {
        array_walk($rules, fn($config, $rule) => $this->register_rule($rule, $config));
    }

    final protected function rule(string $rule) {

        if (!isset($this->rules_map[$rule]))
            throw new \Exception("Переданное правило '{$rule}' не зарегистрировано в ".get_called_class());

        $rule_config = $this->rules_map[$rule];

        $default = $rule_config['default'] ?? $rule_config[0] ?? null;
        $rule = $rule_config['rule'] ?? $rule_config[1] ?? null;
        $before = $rule_config['before'] ?? $rule_config[2] ?? null;
        $after = $rule_config['after'] ?? $rule_config[3] ?? null;

        return [
            2 => $before,
            'before' => $before,
            3 => $after,
            'after' => $after,
            0 => $default,
            'default' => $default,
            1 => $rule,
            'rule' => $rule,
        ];
    }

    final protected function curl_init($request_url, $request_method, array $params = []) {
        $this->trigger('before_curl_init', $request_url, $request_method, $params);

        $curl = curl_init();

        $this->trigger('curl_init', $request_url, $request_method, $curl);

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
            return $this->methods_map[$method]($params);
        
        $config = $this->methods_map[$method];

        $this->prepare_params($config['params'], $params, $config['on_prepare']);

        $this->trigger('call', $method, $params);

        [$request_url, $point] = $this->build_url($method, $config['point']);

        $this->trigger('build_url', $request_url, $method, $params);

        if (!filter_var($request_url, FILTER_VALIDATE_URL))
            throw new \Exception("Задан некорректный путь для API: '{$request_url}' в ".get_called_class());
    
        $curl = $this->curl_init($request_url, $config['method'], $params);

        if ($config['cache_ttl'] > 0 && !empty(static::$CACHE_DIRECTORY)) {
            $cache_path = rtrim(static::$CACHE_DIRECTORY, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.md5($request_url.'|'.json_encode($params)).'.json';

            $lock = fopen($cache_path.'.lock', 'c');
            if ($lock === false) throw new \RuntimeException("Cannot open lock file {$cache_path}.lock");
            flock($lock, LOCK_EX);

            if (
                is_file($cache_path) && 
                is_readable($cache_path)
            ) {
                $meta = @json_decode(@file_get_contents($cache_path), true) ?: [];

                if ($meta['cache_expires_in'] ?? 0 > time())
                    $cached_data = $meta['response_data'];
            }

            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($cache_path.'.lock');
        }

        $response_data = empty($cached_data)
            ? $this->execute_curl($curl)
            : $cached_data;

        if ($cache_path && empty($cached_data)) {
            $lock = fopen($cache_path.'.lock', 'c');
            if ($lock === false) throw new \RuntimeException("Cannot open lock file {$cache_path}.lock");
            flock($lock, LOCK_EX);

            $meta = [
                'cache_expires_in' => time() + $config['cache_ttl'],
                'response_data' => $response_data
            ];

            @file_put_contents($cache_path, json_encode($meta));

            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($cache_path.'.lock');
        }

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

    public function __get(string $name) {
        switch ($name) {
            case 'methods_map':
            case 'rules_map':
                return $this->$name;
        }

        trigger_error(sprintf('Undefined property: %s::$%s', static::class, $name), E_USER_NOTICE);
    }

}