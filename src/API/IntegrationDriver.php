<?php

namespace ST_system\API;

use ST_system\Rule;
use ST_system\Cache\Manager as Cache;
use ST_system\Traits\HasConfig;
use ST_system\Traits\HasEvents;

abstract class IntegrationDriver {

    use HasConfig;

    protected static function getDefaultConfig(): array {
        return [
            'endpoint'  => '',
            // Без них зависший эндпоинт вешает вызывающий процесс навсегда.
            // Секунды; 0 — без ограничения. Имена те же, что в Storage\File.
            'timeout'         => 30.0,
            'connect_timeout' => 10.0,
            'cache' => [
                'dir' => '',
                'use' => false,
                'driver' => 'filesystem'
            ],
        ];
    }

    use HasEvents;

    protected static function getReservedEvents(): array {
        return [
            '__construct', 'before_curl_init', 'curl_init', 'encode_request',
            'before_call', 'call', 'build_url', 'prepare_response',
            'curl_error', 'decode_response', 'response', 'save_cache',
        ];
    }

    private array $methods_map = [];

    protected ?Cache $cache = null;

    final public static function create(...$params): self {
        return new static(...$params);
    }

    protected function __init(): void {}

    final public function __construct(...$args) {
        Rule::scope(static::class, fn() => $this->__init());

        if (static::config('cache.use'))
            $this->cache = Cache::make([static::class, ...$args], static::config('cache'));
        
        $this->fire('__construct', ...$args);
    }

    protected function getEndpoint(): string {
        return (string)(static::config('endpoint') ?? '');
    }

    final protected function cache(): ?Cache {
        return $this->cache;
    }

    public function purge(): void {
        if ($this->cache) $this->cache->purge();
    }

    final public function purgeBase(): void {
        if ($this->cache) $this->cache->purgeBase();
    }

    private function normalize_method(string $method): string {
        return preg_replace('/\{[^}]+\}/', '{%}', $method);
    }

    final protected function registerMethod(string $method, $config = []): self {
        $n_method = $this->normalize_method($method);

        foreach ($this->methods_map as $m => $c)
            if ($this->normalize_method($m) == $n_method)
                throw new \Exception("Метод '{$n_method}' уже зарегистрирован в ".get_called_class());

        if ($config instanceof \Closure) {
            $this->methods_map[$method] = $config;
            return $this;
        }

        if (!is_array($config))
            throw new \Exception("Конфигурация метода '{$method}' должна быть массивом в ".get_called_class());

        if (preg_match_all('/\{([^}]+)\}/', $method, $m)) {
            if (!is_array($config['params'] ?? null)) $config['params'] = [];

            array_walk($m[1], function (string $param_name) use (&$config) {
                if (!array_key_exists($param_name, $config['params']))
                    $config['params'][$param_name] = Rule::create(fn(&$v) => is_string($v) && $v !== '')
                        ->handleError(fn($v) => "Не передан обязательный параметр {$param_name}!")
                        ->after(fn(&$v) => $v = trim($v, '/\\'))
                        ->skip(true);
            });
        }

        $ep = $config['endpoint'] ?? null;
        $config['endpoint']     = (is_string($ep) && filter_var($ep, FILTER_VALIDATE_URL)) ? $ep : $this->getEndpoint();
        $config['headers']      = is_array($config['headers'] ?? null)     ? $config['headers']    : [];
        $config['content_type'] = is_string($config['content_type'] ?? null) ? $config['content_type'] : 'application/x-www-form-urlencoded';
        $http                   = strtoupper((string)($config['method'] ?? 'GET'));
        $config['method']       = in_array($http, ['GET', 'POST'], true)   ? $http                 : 'GET';
        $config['params']       = is_array($config['params'] ?? null)       ? $config['params']     : [];
        if (!empty($config['params']))
            $config['params'] = Rule::scope(static::class, fn() => Rule::object($config['params']));
        $config['on_prepare']   = is_callable($config['on_prepare'] ?? null) ? $config['on_prepare'] : null;
        $config['cache_ttl']    = is_int($config['cache_ttl'] ?? null)      ? $config['cache_ttl']  : 0;
        $config['meta']         = is_array($config['meta'] ?? null)         ? $config['meta']       : [];

        $config['timeout']         = is_numeric($config['timeout'] ?? null)
            ? (float)$config['timeout']         : (float)static::config('timeout');
        $config['connect_timeout'] = is_numeric($config['connect_timeout'] ?? null)
            ? (float)$config['connect_timeout'] : (float)static::config('connect_timeout');

        $this->methods_map[$method] = $config;

        return $this;
    }

    final protected function unregisterMethod(string $method): self {
        unset($this->methods_map[$method]);
        return $this;
    }

    final protected function registerMethodsMap(array $methods): self {
        array_walk($methods, fn($config, $method) => $this->registerMethod($method, $config));
        return $this;
    }

    final protected function unregisterMethodsMap(array $methods): self {
        array_walk($methods, fn($method) => $this->unregisterMethod($method));
        return $this;
    }

    final protected function curl_init($request_url = '', $method = '', $params = [], $config = []) {
        $this->fire('before_curl_init', $request_url, $method, $params, $config);

        $curl = curl_init();

        // Ставим ДО события curl_init, чтобы драйвер мог переопределить. 0 — без ограничения.
        $timeout         = (float)($config['timeout']         ?? 0);
        $connect_timeout = (float)($config['connect_timeout'] ?? 0);

        if ($timeout > 0)         curl_setopt($curl, CURLOPT_TIMEOUT_MS,        (int)round($timeout * 1000));
        if ($connect_timeout > 0) curl_setopt($curl, CURLOPT_CONNECTTIMEOUT_MS, (int)round($connect_timeout * 1000));

        $this->fire('curl_init', $curl);

        if ($this->fire('encode_request', $request_url, $method, $params, $config) === false) {
            switch ($config['content_type']) {
                case 'application/x-www-form-urlencoded': $params = http_build_query($params); break;
                case 'application/json':                  $params = json_encode($params);       break;
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
                    CURLOPT_POST       => true,
                    CURLOPT_POSTFIELDS => $params,
                ]);
                break;
            default:
                throw new \Exception("Метод запроса '{$config['method']}' не поддерживается в ".get_called_class());
        }

        $headers = [];
        foreach ($config['headers'] as $k => $v) {
            if (is_int($k) && is_string($v) && strpos($v, ':') !== false)
                [$k, $v] = array_map('trim', explode(':', $v, 2));
            $headers[str_replace(' ', '-', ucwords(strtolower(str_replace(['_', '-'], ' ', $k))))] = $v;
        }

        curl_setopt_array($curl, [
            CURLOPT_URL            => $request_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array_map(fn($v, $k) => "$k: $v", $headers, array_keys($headers)),
        ]);

        return $curl;
    }

    final protected function build_url(string $method, string $endpoint = '') {
        $endpoint = filter_var($endpoint, FILTER_VALIDATE_URL)
            ? $endpoint
            : (isset($this->methods_map[$method]['endpoint'])
                ? $this->methods_map[$method]['endpoint']
                : $this->getEndpoint());

        $request_url = $endpoint.'/'.$method;

        [$p, $u] = preg_match('#^(https?://)#i', $request_url, $matches)
            ? [$matches[1], substr($request_url, strlen($matches[1]))]
            : ['', $request_url];

        return [$p.preg_replace('#/{2,}#', '/', explode('?', $u, 2)[0]), $endpoint];
    }

    final protected function execute_curl($curl): array {
        $response_data = [
            'response'      => curl_exec($curl),
            'error'         => curl_error($curl),
            'http_code'     => curl_getinfo($curl, CURLINFO_HTTP_CODE),
            'effective_url' => curl_getinfo($curl, CURLINFO_EFFECTIVE_URL),
        ];
        curl_close($curl);
        return $response_data;
    }

    final public function call(string $method, array $params = []) {
        if (!isset($this->methods_map[$method])) {
            $method_parts = explode('/', trim($method, '/'));

            foreach ($this->methods_map as $candidate => $c) {
                $candidate_parts = explode('/', trim($candidate, '/'));

                if (count($candidate_parts) !== count($method_parts)) continue;

                $ok       = true;
                $captures = [];
                foreach ($candidate_parts as $i => $part)
                    if (preg_match('/^\{(\w+)\}$/', $part, $mm))
                        $captures[$mm[1]] = $method_parts[$i];
                    elseif ($part !== $method_parts[$i]) { $ok = false; break; }

                if ($ok) {
                    $method = $candidate;
                    $params = array_merge($captures, $params);
                    break;
                }
            }
        }

        if (!isset($this->methods_map[$method]))
            throw new \Exception("Метод '{$method}' не зарегистрирован в ".get_called_class());

        $config = $this->methods_map[$method];

        if ($config instanceof \Closure)
            return $config($params);

        $this->fire('before_call', $method, $params);

        Rule::scope(static::class, function() use ($config, &$params) {
            if (!empty($config['params'])) {
                $rule = $config['params'];
                $errors = ($rule instanceof Rule)
                    ? $rule->apply($params)
                    : Rule::object($rule)->apply($params);
                if (!empty($errors))
                    throw new \InvalidArgumentException($errors[0]);
            }
            if ($config['on_prepare'] !== null)
                ($config['on_prepare'])($params);
        });

        $params = array_filter($params, fn($v) => $v !== null);

        $this->fire('call', $method, $params);

        $method = preg_replace_callback('/\{(\w+)\}/', function ($m) use (&$params) {
            if (!array_key_exists($m[1], $params))
                throw new \Exception("Не передан обязательный параметр {$m[1]}!");
            $v = $params[$m[1]];
            unset($params[$m[1]]);
            return $v;
        }, $method);

        [$request_url, $endpoint] = $this->build_url($method, $config['endpoint']);

        $this->fire('build_url', $request_url, $endpoint, $method, $params);

        if (!filter_var($request_url, FILTER_VALIDATE_URL))
            throw new \Exception("Задан некорректный путь для API: '{$request_url}' в ".get_called_class());

        $curl = $this->curl_init($request_url, $method, $params, $config);

        $from_cache = false;
        $raw_data   = null;
        $cache = null;

        if (($config['cache_ttl'] > 0 || $config['cache_ttl'] === -1) && $this->cache !== null) {
            $cache = $this->cache->make([$request_url, $params], ['ttl' => $config['cache_ttl']]);

            if ($cache->isValid()) {
                $cached = $cache->get();
                if ($cached !== null) {
                    $raw_data   = is_string($cached) ? (array)json_decode($cached, true) : $cached;
                    $from_cache = true;
                }
            }
        }

        if ($raw_data === null)
            $raw_data = $this->execute_curl($curl);

        $this->fire('prepare_response', $method, $params, $raw_data);

        if ($raw_data['error']) {
            if ($this->fire('curl_error', $method, $params, $raw_data) === false)
                throw new \Exception("Ошибка при запросе '{$method}' к API: '{$raw_data['error']}' в ".get_called_class());

            return false;
        }

        if ($this->fire('decode_response', $method, $params, $raw_data) === false) {
            $response = @json_decode($raw_data['response'], true);

            if (json_last_error() !== JSON_ERROR_NONE)
                throw new \Exception("Ошибка при декодировании ответа: '".json_last_error_msg()."' в ".get_called_class());
        } else {
            $response = $raw_data['response'];
        }

        $this->fire('response', $method, $params, $response);

        if ($cache !== null && !$from_cache) {
            $meta = ['ttl' => 0];
            $this->fire('save_cache', $method, $params, $response, $meta);
            $effective_ttl = !empty($meta['ttl']) ? (int)$meta['ttl'] : $config['cache_ttl'];
            $cache->set(json_encode($raw_data), $effective_ttl);
        }

        return $response;
    }

}
