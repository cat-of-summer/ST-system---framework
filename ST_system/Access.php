<?php

namespace ST_system;

use ST_system\Traits\HasConfig;
use ST_system\HTTP\Request;
use ST_system\HTTP\Response;
use ST_system\Rule;

final class Access {

    use HasConfig;

    protected static function getDefaultConfig(): array {
        return [
            'credentials' => [
                'name' => 'pass',
                'password' => date('dm')
            ],
            'CORS' => [
                'methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'],
                'headers' => ['Content-Type', 'Authorization', 'X-Requested-With']
            ]
        ];
    }

    private static $block_password = [
        'name' => null,
        'value' => null
    ];

    public static function requestAccess(array $config = []) {
        Rule::scope(static::class, function() use (&$config) {
            Rule::object([
                'name' => 'string|escape_html|defaultConfig:credentials.name',
                'value' => 'string|escape_html|defaultConfig:credentials.value',
                'onFail' => ['callable', Rule::default(fn() => self::throw(401))],
                'onSuccess' => 'nullable|callable'
            ])->throwable()->apply($config);
        });
        
        $val = Request::data($config['name']);

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
                'name'  => 'string|escape_html|defaultConfig:credentials.name',
                'value' => 'string|escape_html|defaultConfig:credentials.value',
            ])->throwable()->apply($config);
        });

        $val = Request::data($config['name']);

        if ($val !== null && $val == $config['value'])
            return $f();
    }

    public static function startBlock(array $config = []) {
        Rule::scope(static::class, function() use (&$config) {
            Rule::object([
                'name'  => 'string|escape_html|defaultConfig:credentials.name',
                'value' => 'string|escape_html|defaultConfig:credentials.value',
            ])->throwable()->apply($config);
        });

        self::$block_password = [
            'name'  => $config['name'],
            'value' => $config['value'],
        ];

        ob_start();
    }

    public static function endBlock() {
        if (!self::$block_password['name'] || !self::$block_password['value']) return;

        $content = ob_get_clean();

        $val = Request::data(self::$block_password['name']);

        if ($val !== null && $val == self::$block_password['value'])
            echo $content;
    }

    public static function throw(int $code = 404) {
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
