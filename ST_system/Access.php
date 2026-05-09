<?php

namespace ST_system;

use ST_system\Traits\HasConfig;
use ST_system\Traits\HasEvents;
use ST_system\HTTP\Request;
use ST_system\HTTP\Response;
use ST_system\Rule;

final class Access {

    use HasConfig;

    use HasEvents {
        on as private _on;
    }

    private function __construct() {}

    private static function getInstance(): self {
        static $instance = null;

        if ($instance === null)
            $instance = new static();

        return $instance;
    }

    protected static function getDefaultConfig(): array {
        return [
            'credentials' => [
                'name' => 'pass',
                'value' => date('dm')
            ],
            'accessMethod' => 'GET',
            'CORS' => [
                'methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'],
                'headers' => ['Content-Type', 'Authorization', 'X-Requested-With']
            ]
        ];
    }

    private static $block = [
        'name'         => null,
        'value'        => null,
        'accessMethod' => null,
    ];

    public static function on(string $event, callable $listener): void {
        self::getInstance()->_on($event, $listener);
    }

    private static function extractCredential(string $name, string $method): ?string {
        switch ($method) {
            case 'GET':     return Request::get($name);
            case 'POST':
            case 'PUT':
            case 'DELETE':
            case 'PATCH':   return Request::post($name);
            case 'HEADERS': return Request::headers($name);
            case 'COOKIE':  return Request::cookie($name);
            case 'SESSION': return $_SESSION[$name] ?? null;
        }

        throw new \InvalidArgumentException("Access method not allowed: '$method'");
    }

    public static function requestAccess(array $config = []) {
        Rule::scope(static::class, function() use (&$config) {
            Rule::object([
                'name'         => 'string|escape_html|defaultConfig:credentials.name',
                'value'        => 'string|escape_html|defaultConfig:credentials.value',
                'accessMethod' => 'nullable|string|defaultConfig:accessMethod',
                'onFail'       => ['callable', Rule::default(fn() => self::throw(401))],
                'onSuccess'    => 'nullable|callable'
            ])->throwable()->apply($config);
        });

        $val = self::extractCredential($config['name'], $config['accessMethod']);

        return (empty($val) || $val != $config['value'])
            ? $config['onFail']
            : $config['onSuccess'];
    }

    public static function httpAccess(array $config = []) {
        Rule::scope(static::class, function() use (&$config) {
            Rule::object([
                'login' => 'string|escape_html|defaultConfig:credentials.name',
                'password' => 'string|escape_html|defaultConfig:credentials.value'
            ])->throwable()->apply($config);
        });

        if (
            !isset($_SERVER['PHP_AUTH_USER']) ||
            !isset($_SERVER['PHP_AUTH_PW']) ||
            $_SERVER['PHP_AUTH_USER'] !== $config['login'] ||
            $_SERVER['PHP_AUTH_PW'] !== $config['password']
        ) {
            Response::status(401)->header('WWW-Authenticate', 'Basic realm="Restricted Area"')->send();
        }
    }

    public static function call(callable $f, array $config = []) {
        Rule::scope(static::class, function() use (&$config) {
            Rule::object([
                'name'         => 'string|escape_html|defaultConfig:credentials.name',
                'value'        => 'string|escape_html|defaultConfig:credentials.value',
                'accessMethod' => 'nullable|string|defaultConfig:accessMethod',
            ])->throwable()->apply($config);
        });

        $val = self::extractCredential($config['name'], $config['accessMethod']);

        if ($val !== null && $val == $config['value'])
            return $f();
    }

    public static function startBlock(array $config = []) {
        Rule::scope(static::class, function() use (&$config) {
            Rule::object([
                'name'         => 'string|escape_html|defaultConfig:credentials.name',
                'value'        => 'string|escape_html|defaultConfig:credentials.value',
                'accessMethod' => 'nullable|string|defaultConfig:accessMethod',
            ])->throwable()->apply($config);
        });

        self::$block = [
            'name'         => $config['name'],
            'value'        => $config['value'],
            'accessMethod' => $config['accessMethod'],
        ];

        ob_start();
    }

    public static function endBlock() {
        if (!self::$block['name'] || !self::$block['value'] || !self::$block['accessMethod']) return;

        $content = ob_get_clean();

        $val = self::extractCredential(self::$block['name'], self::$block['accessMethod']);

        if ($val !== null && $val == self::$block['value'])
            echo $content;
    }

    public static function throw(int $code = 404): void {
        if (self::getInstance()->fire('throw', $code) === false)
            Response::status($code)->header('X-Content-Type-Options', 'nosniff')->send();
    }

    public static function getRequestOrigin(): string {
        static $data = null;

        if ($data === null) {
            $origin = Request::headers('Origin');

            if (!$origin) {
                $referer = Request::headers('Referer');
                $origin = $referer ? (parse_url($referer, PHP_URL_HOST) ?? '') : '';
            }

            $data = (string)$origin;
        }

        return $data;
    }

    public static function getClientIp(): string {
        static $data = null;

        if ($data === null) {
            $forwarded = Request::headers('X-Forwarded-For');

            $data = $forwarded
                ? trim(explode(',', $forwarded)[0])
                : (string)(Request::headers('Client-Ip') ?: $_SERVER['REMOTE_ADDR'] ?? '');
        }

        return $data;
    }

    public static function handleCORS(array $config = []) {
        Rule::scope(static::class, function() use (&$config) {
            Rule::object([
                'allowed_origins'    => ['array', Rule::default(['*'], true), Rule::forEach('url')],
                'forbidden_origins'  => ['sometimes|array|foreach:url'],
                'methods'            => ['array|defaultConfig:CORS.methods', Rule::forEach(['required|string|strtoupper', Rule::in(self::config('CORS.methods'))])],
                'headers'            => ['array|defaultConfig:CORS.headers,foreach:required|string|escape_html'],
            ])->throwable()->apply($config);
        });

        $request_origin = self::getRequestOrigin();

        if (!empty($request_origin) && in_array($request_origin, $config['forbidden_origins']))
            self::throw(403);

        $origin_header = in_array('*', $config['allowed_origins'])
            ? '*'
            : ((!empty($request_origin) && in_array($request_origin, $config['allowed_origins']))
                ? $request_origin
                : null);

        if ($origin_header === null)
            self::throw(403);

        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Allow-Origin: $origin_header");
        header("Access-Control-Allow-Headers: " . implode(", ", $config['headers']));

        if (Request::method() === 'OPTIONS') {
            header("Access-Control-Allow-Methods: " . implode(", ", $config['methods']));
            Response::status(200)->send();
        }
    }
}
