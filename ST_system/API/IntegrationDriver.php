<?php

namespace ST_system\API;

use ST_system\Rule;
use ST_system\Cache;
use ST_system\Main;

abstract class IntegrationDriver {

    protected const DEFAULT_ENDPOINT = '';
    protected const CACHE_DIRECTORY  = '';

    private array $listeners   = [];
    private array $methods_map = [];

    protected ?Cache $driverCache = null;

    /**
     * System events that cannot be triggerd via the public trigger() method.
     * Drivers use on() to listen to these; they are triggerd internally by trigger().
     */
    private const RESERVED_EVENTS = [
        '__construct', 'before_curl_init', 'curl_init', 'encode_request',
        'before_call', 'call', 'build_url', 'prepare_response',
        'curl_error', 'decode_response', 'response', 'save_cache',
    ];

    // ── Events ───────────────────────────────────────────────────────

    final protected function on(string $event, callable $listener): void {
        $this->listeners[$event][] = $listener;
    }

    /** Internal event dispatch — unrestricted. */
    private function fire(string $event, &...$params) {
        if (empty($this->listeners[$event]))
            return false;

        foreach ($this->listeners[$event] as $listener)
            call_user_func_array($listener, $params);
    }

    /**
     * Fire a custom (non-reserved) event from subclass code.
     * Throws LogicException when a reserved event name is used.
     */
    final protected function trigger(string $event, &...$params) {
        if (in_array($event, self::RESERVED_EVENTS, true))
            throw new \LogicException("Event '{$event}' is reserved and cannot be triggered externally.");

        return $this->fire($event, ...$params);
    }

    // ── Bootstrap ────────────────────────────────────────────────────

    final public static function create(...$params): self {
        return new static(...$params);
    }

    protected function __init(): void {}

    final public function __construct(...$args) {
        self::registerDriverRules();
        $this->__init();
        $this->fire('__construct', ...$args);
    }

    /**
     * Register extra Rule aliases needed by all drivers (called once per process).
     */
    private static function registerDriverRules(): void {
        static $done = false;
        if ($done) return;
        $done = true;

        if (!Rule::get('secure_url'))
            Rule::create(fn(&$v) => is_string($v) && filter_var($v, FILTER_VALIDATE_URL) !== false && stripos($v, 'https://') === 0)
                ->handleError(fn($v) => 'Must be a valid HTTPS URL')
                ->alias('secure_url');

        if (!Rule::get('float_range'))
            Rule::create(function(&$v, array $p): bool {
                if ($v === null) return true;
                $min = isset($p[0]) ? (float)$p[0] : PHP_INT_MIN;
                $max = isset($p[1]) ? (float)$p[1] : PHP_INT_MAX;
                return is_numeric($v) && (float)$v >= $min && (float)$v <= $max;
            })
            ->after(fn(&$v) => $v = $v === null ? null : (float)$v)
            ->handleError(fn($v) => 'Value out of range')
            ->alias('float_range');

        if (!Rule::get('message'))
            Rule::create(function(&$v): bool {
                if (!is_array($v) || !isset($v['role']) || !array_key_exists('content', $v))
                    throw new \Exception('Each message must be an array with keys role and content');
                if (!in_array($v['role'], ['system', 'user', 'assistant', 'tool'], true))
                    throw new \Exception('Message role must be one of: system, user, assistant, tool');
                return true;
            })
            ->handleError(fn($v) => 'Invalid message object')
            ->alias('message');

        if (!Rule::get('array_of_messages'))
            Rule::forEach(Rule::get('message'))
                ->order(800)
                ->handleError(fn($v) => 'The messages parameter is invalid or empty')
                ->alias('array_of_messages');
    }

    // ── Endpoint ─────────────────────────────────────────────────────

    /**
     * Returns the base endpoint URL for this driver.
     * Override in subclasses to return a dynamic host (e.g. from config, region, etc.).
     * Supports any valid URL including non-standard ports: https://api.example.com:8080/v2
     */
    protected function getEndpoint(): string {
        return static::DEFAULT_ENDPOINT;
    }

    // ── Driver Cache ──────────────────────────────────────────────────

    /**
     * Initialize the per-driver Cache instance.
     *
     * Call from __init() or a '__construct' event to enable cacheGet/cacheSet/cacheInstance.
     * If both $config['dir'] and CACHE_DIRECTORY are empty, this is a no-op.
     *
     * @param mixed $key    Cache key (any serializable value)
     * @param array $config Optional overrides: 'dir', 'ttl'
     */
    final protected function initCache($key, array $config = []): void {
        $dir = $config['dir'] ?? static::CACHE_DIRECTORY;
        if (!is_string($dir) || $dir === '') return;

        $this->driverCache = new Cache($key, array_merge([
            'dir' => $dir,
            'ttl' => 3600,
        ], $config));
    }

    /** Read a value from the driver cache. Returns $default if absent or expired. */
    final protected function cacheGet(string $name = 'data', $default = null) {
        if ($this->driverCache === null || !$this->driverCache->isValid())
            return $default;
        $v = $this->driverCache->get($name);
        return $v !== null ? $v : $default;
    }

    /** Write a value to the driver cache. */
    final protected function cacheSet($value, string $name = 'data', int $ttl = 0): void {
        if ($this->driverCache !== null)
            $this->driverCache->set($value, $name, $ttl);
    }

    /** Return the raw Cache instance (or null if not initialized). */
    final protected function cacheInstance(): ?Cache {
        return $this->driverCache;
    }

    // ── Method registration ──────────────────────────────────────────

    private function normalize_method(string $method): string {
        return preg_replace('/\{[^}]+\}/', '{%}', $method);
    }

    /**
     * Register a single API method.
     *
     * $config may be:
     *  - array  with keys: endpoint, headers, content_type, method, params,
     *                      on_prepare, cache_ttl, meta
     *  - Closure  that receives (array $params) and returns the response directly,
     *             bypassing the normal curl/cache flow entirely.
     */
    final protected function register_method(string $method, $config = []): self {
        $n_method = $this->normalize_method($method);

        foreach ($this->methods_map as $m => $c)
            if ($this->normalize_method($m) == $n_method)
                throw new \Exception("Метод '{$n_method}' уже зарегистрирован в ".get_called_class());

        // Closure-based method: bypass normal flow
        if ($config instanceof \Closure) {
            $this->methods_map[$method] = $config;
            return $this;
        }

        if (!is_array($config))
            throw new \Exception("Конфигурация метода '{$method}' должна быть массивом в ".get_called_class());

        // Auto-register URL path params for methods with {placeholders}
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

        // Normalize config with safe defaults
        $ep = $config['endpoint'] ?? null;
        $config['endpoint']     = (is_string($ep) && filter_var($ep, FILTER_VALIDATE_URL)) ? $ep : $this->getEndpoint();
        $config['headers']      = is_array($config['headers'] ?? null)     ? $config['headers']    : [];
        $config['content_type'] = is_string($config['content_type'] ?? null) ? $config['content_type'] : 'application/x-www-form-urlencoded';
        $http                   = strtoupper((string)($config['method'] ?? 'GET'));
        $config['method']       = in_array($http, ['GET', 'POST'], true)   ? $http                 : 'GET';
        $config['params']       = is_array($config['params'] ?? null)       ? $config['params']     : [];
        $config['on_prepare']   = is_callable($config['on_prepare'] ?? null) ? $config['on_prepare'] : null;
        $config['cache_ttl']    = is_int($config['cache_ttl'] ?? null)      ? $config['cache_ttl']  : 0;
        $config['meta']         = is_array($config['meta'] ?? null)         ? $config['meta']       : [];

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

    // ── HTTP / CURL ──────────────────────────────────────────────────

    final protected function curl_init($request_url, $method, $params, $config) {
        $this->fire('before_curl_init', $request_url, $method, $params, $config);

        $curl = curl_init();

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
            'response'  => curl_exec($curl),
            'error'     => curl_error($curl),
            'http_code' => curl_getinfo($curl, CURLINFO_HTTP_CODE),
        ];
        curl_close($curl);
        return $response_data;
    }

    // ── Call ─────────────────────────────────────────────────────────

    final public function call(string $method, array $params = []) {

        // Resolve parametric routes like 'users/{id}' → 'users/42'
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

        // Closure-based methods execute directly, bypassing all infrastructure
        if ($config instanceof \Closure)
            return $config($params);

        $this->fire('before_call', $method, $params);

        // Validate and transform params via Rule schema
        if (!empty($config['params'])) {
            $errors = Rule::object($config['params'])->apply($params);
            if (!empty($errors))
                throw new \InvalidArgumentException($errors[0]);
        }
        if ($config['on_prepare'] !== null)
            ($config['on_prepare'])($params);

        // Remove null values — don't send null params to APIs
        $params = array_filter($params, fn($v) => $v !== null);

        $this->fire('call', $method, $params);

        // Substitute {param} placeholders in method path
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

        // ── Cache read ───────────────────────────────────────────────
        $from_cache = false;
        $raw_data   = null;
        /** @var Cache|null $cache */
        $cache = null;

        if ($config['cache_ttl'] > 0 && is_string(static::CACHE_DIRECTORY) && static::CACHE_DIRECTORY !== '') {
            $cache = new Cache([$request_url, $params, static::class], [
                'dir' => static::CACHE_DIRECTORY,
                'ttl' => $config['cache_ttl'],
            ]);

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

        // ── Cache write ──────────────────────────────────────────────
        if ($cache !== null && !$from_cache) {
            $meta = ['ttl' => 0];
            $this->fire('save_cache', $method, $params, $response, $meta);
            $effective_ttl = !empty($meta['ttl']) ? (int)$meta['ttl'] : $config['cache_ttl'];
            $cache->set(json_encode($raw_data), '', $effective_ttl);
        }

        return $response;
    }

    // ── Method Override / Extend ──────────────────────────────────────────────

    /**
     * Replace an existing method's config (merge with existing, or replace with Closure).
     * Uses Main::merge() for array configs (deep merge).
     */
    final public function override_method(string $method, $config = []): self {
        if (!isset($this->methods_map[$method]))
            throw new \Exception("Метод '{$method}' не зарегистрирован в ".get_called_class());

        $old_config = $this->methods_map[$method];
        $this->unregister_method($method);

        $this->register_method($method, $config instanceof \Closure
            ? $config
            : Main::merge($old_config, $config)
        );

        return $this;
    }

    final public function override_methods_map(array $methods): self {
        array_walk($methods, fn($config, $method) => $this->override_method($method, $config));
        return $this;
    }

    /**
     * Merge additional $extra_params into an existing method's 'params' schema.
     * Useful for adding custom fields to e.g. Bitrix24 methods without replacing the whole config.
     *
     * @param string $method       Registered method name
     * @param array  $extra_params Additional Rule/string entries keyed by field name
     */
    final public function extend_method(string $method, array $extra_params): self {
        if (!isset($this->methods_map[$method]))
            throw new \Exception("Метод '{$method}' не зарегистрирован в ".get_called_class());

        if ($this->methods_map[$method] instanceof \Closure)
            throw new \Exception("Метод '{$method}' является Closure и не поддерживает extend в ".get_called_class());

        $this->methods_map[$method]['params'] = array_merge(
            $this->methods_map[$method]['params'],
            $extra_params
        );

        return $this;
    }

    final public function extend_methods_map(array $methods): self {
        array_walk($methods, fn($extra_params, $method) => $this->extend_method($method, $extra_params));
        return $this;
    }

}