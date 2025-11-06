<?php

namespace ST_system\API;

use ST_system\Traits\HasValidatableParams;

abstract class Integration_driver {

    use HasValidatableParams;

    protected const DEFAULT_POINT = '';
    protected const CACHE_DIRECTORY = '';
    
    private static $CACHE_DIRECTORY = null;

    private array $listeners = [];
    private array $methods_map = [];

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

    protected function __init() {}

    final public function __construct(...$args) {
        if (static::$CACHE_DIRECTORY === null && is_string(static::CACHE_DIRECTORY) && static::CACHE_DIRECTORY != '') {
            if (strpos(static::CACHE_DIRECTORY, '~') === 0)
                static::$CACHE_DIRECTORY = $_SERVER['DOCUMENT_ROOT'].DIRECTORY_SEPARATOR.trim(static::CACHE_DIRECTORY, DIRECTORY_SEPARATOR.'~');
            elseif (strpos(static::CACHE_DIRECTORY, DIRECTORY_SEPARATOR) !== 0)
                static::$CACHE_DIRECTORY = __DIR__.DIRECTORY_SEPARATOR.trim(static::CACHE_DIRECTORY, DIRECTORY_SEPARATOR);

            static::$CACHE_DIRECTORY .= DIRECTORY_SEPARATOR.str_replace('\\', DIRECTORY_SEPARATOR, static::class);

            if (!is_dir(static::$CACHE_DIRECTORY)) {
                mkdir(static::$CACHE_DIRECTORY, 0775, true);

                if (!is_dir(static::$CACHE_DIRECTORY))
                    static::$CACHE_DIRECTORY = '';
            }
        }

        static::register_rules_map([
            'string' => [null, fn($v) => is_string($v) && $v != '', 'after' => fn($v) => htmlspecialchars($v)],
            '*string' => [fn($k) => new \Exception("Переданный параметр {$k} должен быть строкой!"), fn($v) => is_string($v) && $v != '', 'after' => fn($v) => htmlspecialchars($v)],
            'bool' => [null, 'after' => fn($v) => (bool)$v],
            '*bool' => [fn($k) => new \Exception("Переданный параметр {$k} должен быть булевым значением!"), fn($v) => !is_null($v), 'after' => fn($v) => (bool)$v],
            'int' => [null, fn($v) => is_int($v), fn($v) => is_numeric($v) ? (int)$v : $v],
            '*int' => [fn($k) => new \Exception("Переданный параметр {$k} должен быть числом!"), fn($v) => is_int($v), fn($v) => is_numeric($v) ? (int)$v : $v],
            'float' => [null, fn($v) => is_float($v), fn($v) => is_numeric($v) ? (float)$v : $v],
            '*float' => [fn($k) => new \Exception("Переданный параметр {$k} должен быть числом с плавающей точкой!"), fn($v) => is_float($v), fn($v) => is_numeric($v) ? (float)$v : $v],
            'email' => [null, fn($v) => filter_var($v, FILTER_VALIDATE_EMAIL)],
            '*email' => [fn($k) => new \Exception("Переданный параметр {$k} не является электронной почтой!"), fn($v) => filter_var($v, FILTER_VALIDATE_EMAIL)],
            'url' => [null, fn($v) => is_string($v) && filter_var($v, FILTER_VALIDATE_URL)],
            '*url' => [fn($k) => new \Exception("Переданный параметр {$k} не является корректным URL!"), fn($v) => is_string($v) && filter_var($v, FILTER_VALIDATE_URL)],
        ]);

        $this->__init();
        $this->trigger('__construct', ...$args);
    }

    private function normalize_method(string $method) {
        return preg_replace('/\{[^}]+\}/', '{%}', $method);
    }

    final protected function register_method(string $method, $config = []): self {
        $n_method = $this->normalize_method($method);

        foreach ($this->methods_map as $m => $c)
            if ($this->normalize_method($m) == $n_method)
                throw new \Exception("Метод '{$n_method}' уже зарегистрирован в ".get_called_class());

        if (!is_array($config))
            throw new \Exception("Конфигурация метода '{$method}' должна быть массивом ".get_called_class());
        
        if (preg_match_all('/\{([^}]+)\}/', $method, $m)) {
            if (!is_array($config['params'])) $config['params'] = [];

            array_walk($m[1], function ($param_name) use (&$config) {
                if (!array_key_exists($param_name, $config['params']))
                    $config['params'][$param_name] = [new \Exception("Не передан обязательный параметр {$param_name}!"), fn($v) => is_string($v) && $v != '', 'after' => fn($v) => trim($v, '/\\')];
            });
        }
            
        $this->prepare_params([
            'point' => [static::DEFAULT_POINT, fn($v) => !empty($v) && filter_var($v, FILTER_VALIDATE_URL)],
            'headers' => [[], fn($v) => is_array($v)],
            'content_type' => ['application/x-www-form-urlencoded', fn($v) => is_string($v)],
            'method' => ['GET', fn($v) => in_array(strtoupper($v), ['GET', 'POST']), fn($v) => strtoupper($v)],
            'params' => [[], fn($v) => is_array($v)],
            'on_prepare' => [false, fn($v) => is_callable($v)],
            'cache_ttl' => [0, fn($v) => is_int($v)],
            'meta' => [[]],
        ], $config);

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

    final protected function curl_init($request_url, $method, $params, $config) {
        $this->trigger('before_curl_init', $request_url, $method, $params, $config);

        $curl = curl_init();

        $this->trigger('curl_init', $curl);

        if ($this->trigger('encode_request', $request_url, $method, $params, $config) === false) {
            switch ($config['content_type']) {
                case 'application/x-www-form-urlencoded': $params = http_build_query($params); break;
                case 'application/json': $params = json_encode($params); break;
                default:
                    throw new \Exception("Формат контента запроса '{$config['content_type']}' не поддерживается в ".get_called_class());
            }

            $config['headers']['Content-type'] = $config['content_type'];
        }

        switch ($config['method']) {
            case 'GET':
                $request_url .= '?'.$params;
                break;
            case 'POST':
                curl_setopt_array($curl, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $params
                ]);
                break;
            default:
                throw new \Exception("Метод запроса '{$config['method']}' не поддерживается в ".get_called_class());
        }

        $headers = [];
        foreach ($config['headers'] as $k => $v) {
            if (is_int($k) && is_string($v) && strpos($v, ':') !== false)
                [$k, $v] = array_map('trim', explode(':', $v, 2));
            
            $headers[str_replace(' ', '-', ucwords(strtolower(str_replace(['_','-'], ' ', $k))))] = $v;
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $request_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_map(fn($v, $k) => "$k: $v", $headers, array_keys($headers)),
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

        if (!isset($this->methods_map[$method])) {
            $method_parts = explode('/', trim($method, '/'));

            foreach ($this->methods_map as $candidate => $c) {
                $candidate_parts = explode('/', trim($candidate, '/'));

                if (count($candidate_parts) != count($method_parts)) continue;
                
                $ok = true;
                $captures = [];
                foreach ($candidate_parts as $i => $part)
                    if (preg_match('/^\{(\w+)\}$/', $part, $mm))
                        $captures[$mm[1]] = $method_parts[$i];
                    elseif ($part != $method_parts[$i]) {
                        $ok = false;
                        break;
                    }

                if ($ok) {
                    $method  = $candidate;
                    $params = array_merge($captures, $params);
                    break;
                }
            }
        }

        if (!isset($this->methods_map[$method]))
            throw new \Exception("Метод '{$method}' не зарегистрирован в ".get_called_class());

        $this->trigger('before_call', $method, $params);
                
        $config = $this->methods_map[$method];

        static::prepare_params($config['params'], $params, $config['on_prepare']);

        $this->trigger('call', $method, $params);

        $method = preg_replace_callback('/\{(\w+)\}/', function($m) use (&$params) {
            if (!array_key_exists($m[1], $params))
                throw new \Exception("Не передан обязательный параметр {$m[1]}!");
            $v = $params[$m[1]];
            unset($params[$m[1]]);
            return $v;
        }, $method);

        [$request_url, $point] = $this->build_url($method, $config['point']);

        $this->trigger('build_url', $request_url, $method, $params);

        if (!filter_var($request_url, FILTER_VALIDATE_URL))
            throw new \Exception("Задан некорректный путь для API: '{$request_url}' в ".get_called_class());
    
        $curl = $this->curl_init($request_url, $method, $params, $config);

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

                if (($meta['cache_expires_in'] ?? 0) > time())
                    $cached_data = $meta['raw_data'];
            }

            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($cache_path.'.lock');
        }

        $raw_data = empty($cached_data)
            ? $this->execute_curl($curl)
            : $cached_data;

        $this->trigger('prepare_response', $method, $params, $raw_data);

        if ($raw_data['error']) {
            if ($this->trigger('curl_error', $method, $params, $raw_data) === false)
                throw new \Exception("Ошибка при запросе '{$method}' к API: '{$raw_data['error']}' в ".get_called_class());

            return false;
        } else {
            if ($this->trigger('decode_response', $method, $params, $raw_data) === false) {
                $response = @json_decode($raw_data['response'], true);

                if (json_last_error() !== JSON_ERROR_NONE)
                    throw new \Exception("Ошибка при декодировании ответа: '".json_last_error_msg()."' в ".get_called_class());
                
            } else
                $response = $raw_data['response'];

            $this->trigger('response', $method, $params, $response);
            
            if (isset($cache_path) && empty($cached_data)) {
                $lock = fopen($cache_path.'.lock', 'c');
                if ($lock === false) throw new \RuntimeException("Cannot open lock file {$cache_path}.lock");
                flock($lock, LOCK_EX);

                $meta = [
                    'cache_expires_in' => time() + $config['cache_ttl'],
                    'raw_data' => $raw_data
                ];
                
                $this->trigger('save_cache', $method, $params, $response, $meta);

                if (!empty($meta))
                    @file_put_contents($cache_path, json_encode($meta));
                
                flock($lock, LOCK_UN);
                fclose($lock);
                @unlink($cache_path.'.lock');
            }

            return $response;
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