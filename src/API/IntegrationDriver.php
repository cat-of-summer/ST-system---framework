<?php

namespace ST_system\API;

use ST_system\Rule;
use ST_system\HTTP\WebClient;
use ST_system\Cache\CacheManager as Cache;
use ST_system\Traits\HasConfig;
use ST_system\Traits\HasEvents;

abstract class IntegrationDriver {

    use HasConfig;

    protected static function getDefaultConfig(): array {
        return [
            'endpoint'  => '',
            'timeout'         => 30.0,
            'connect_timeout' => 10.0,
            'verify'          => true,
            'requeue'         => 0,
            'batch'           => 10,
            'delay'           => 0,
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
            '__construct', 'before_curl_init', 'encode_request',
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

    final protected function methodConfig(string $method = '') {
        if ($method === '') return $this->methods_map;
        return $this->methods_map[$method] ?? [];
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
        $config['verify']          = array_key_exists('verify', $config)
            ? (bool)$config['verify']           : (bool)static::config('verify');
        $config['requeue']         = is_int($config['requeue'] ?? null)
            ? $config['requeue']                : (int)static::config('requeue');

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

    final protected function request(string $url, string $method, array $params, array $config): array {
        $client = $this->buildClient($url, $method, $params, $config);
        if ($params) $client->fill($params);

        $results = $client->send();
        return $this->mapResult($results[0] ?? null, $url);
    }

    private function buildClient(string $url, string $method, array &$params, array $config): WebClient {
        $http_method = strtoupper((string)($config['method'] ?? 'GET'));
        $headers     = is_array($config['headers'] ?? null) ? $config['headers'] : [];

        if ($this->fire('encode_request', $url, $method, $params, $config) === false)
            $headers['Content-Type'] = $config['content_type'] ?? 'application/x-www-form-urlencoded';
        elseif (!empty($config['content_type']))
            $headers['Content-Type'] = $config['content_type'];

        if (is_string($params)) {
            parse_str($params, $parsed);
            $params = $parsed;
        }

        $requeue = is_int($config['requeue'] ?? null) ? $config['requeue'] : (int)static::config('requeue');

        $client = WebClient::create($url, [
            'method'          => strtolower($http_method),
            'headers'         => $headers,
            'timeout'         => (float)($config['timeout']         ?? static::config('timeout')),
            'connect_timeout' => (float)($config['connect_timeout'] ?? static::config('connect_timeout')),
            'verify'          => (bool)($config['verify'] ?? static::config('verify')),
            'exception'       => false,
            'requeue'         => $requeue,
        ]);

        $this->attachRetry($client, $requeue);

        return $client;
    }

    private function attachRetry(WebClient $client, int $requeue): void {
        if ($requeue === 0) return;

        $client->on('error', function ($spec, array &$result) {
            if (($result['errno'] ?? 0) !== 0 || ($result['status'] ?? 0) >= 500)
                $result['requeue'] = true;
        });
    }

    private function mapResult(?array $r, string $url): array {
        if ($r === null)
            return ['response' => null, 'error' => 'Пустой ответ', 'http_code' => 0, 'effective_url' => $url];

        return [
            'response'      => $r['body'],
            'error'         => (string)($r['error'] ?? ''),
            'http_code'     => (int)($r['status'] ?? 0),
            'effective_url' => (string)($r['effective_url'] ?? $url),
        ];
    }

    final protected function processResponse(string $method, array $params, array &$raw_data) {
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

        return $response;
    }

    private function resolveMethodConfig(string &$method, array &$params) {
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

        return $this->methods_map[$method];
    }

    private function prepareRequest(string $method, array $params, array $config): array {
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

        $this->fire('before_curl_init', $request_url, $method, $params, $config);

        return [
            'method' => $method,
            'url'    => $request_url,
            'params' => $params,
            'config' => $config,
        ];
    }

    final public function call(string $method, array $params = []) {
        $config = $this->resolveMethodConfig($method, $params);

        if ($config instanceof \Closure)
            return $config($params);

        $p = $this->prepareRequest($method, $params, $config);

        $from_cache = false;
        $raw_data   = null;
        $cache      = null;

        if (($config['cache_ttl'] > 0 || $config['cache_ttl'] === -1) && $this->cache !== null) {
            $cache = $this->cache->make([$p['url'], $p['params']], ['ttl' => $config['cache_ttl']]);

            if ($cache->isValid()) {
                $cached = $cache->get();
                if ($cached !== null) {
                    $raw_data   = is_string($cached) ? (array)json_decode($cached, true) : $cached;
                    $from_cache = true;
                }
            }
        }

        if ($raw_data === null)
            $raw_data = $this->request($p['url'], $p['method'], $p['params'], $p['config']);

        $response = $this->processResponse($p['method'], $p['params'], $raw_data);

        if ($response === false)
            return false;

        if ($cache !== null && !$from_cache) {
            $meta = ['ttl' => 0];
            $this->fire('save_cache', $p['method'], $p['params'], $response, $meta);
            $effective_ttl = !empty($meta['ttl']) ? (int)$meta['ttl'] : $config['cache_ttl'];
            $cache->set(json_encode($raw_data), $effective_ttl);
        }

        return $response;
    }

    final public function callMany(array $calls, array $opts = []): array {
        $calls   = array_values($calls);
        $results = array_fill(0, count($calls), null);
        $plans   = [];

        foreach ($calls as $i => $spec) {
            [$method, $params] = $this->normalizeCallSpec($spec);
            $config = $this->resolveMethodConfig($method, $params);

            if ($config instanceof \Closure) {
                $results[$i] = $config($params);
                continue;
            }

            $plans[$i] = $this->prepareRequest($method, $params, $config);
        }

        if (!$plans) return $results;

        $batch = (int)($opts['batch'] ?? static::config('batch'));
        $delay = (int)($opts['delay'] ?? static::config('delay'));

        WebClient::group(function () use ($plans, &$results) {
            foreach ($plans as $i => $p) {
                $reqParams = $p['params'];
                $client    = $this->buildClient($p['url'], $p['method'], $reqParams, $p['config']);

                $client->on('response', function ($spec, array &$r) use (&$results, $i, $p) {
                    $raw = $this->mapResult($r, $p['url']);
                    $results[$i] = $this->processResponse($p['method'], $p['params'], $raw);
                });

                if ($reqParams) $client->fill($reqParams);
            }
        }, ['batch' => $batch, 'delay' => $delay])->send();

        return $results;
    }

    private function normalizeCallSpec($spec): array {
        if (is_string($spec)) return [$spec, []];

        if (is_array($spec)) {
            if (array_key_exists('method', $spec))
                return [(string)$spec['method'], (array)($spec['params'] ?? [])];

            return [(string)($spec[0] ?? ''), (array)($spec[1] ?? [])];
        }

        throw new \InvalidArgumentException(
            "callMany: некорректная спецификация запроса (".gettype($spec).") в ".get_called_class()
        );
    }

}
