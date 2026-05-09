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
                'methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH']
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
        $password_name = isset($config['name']) ? htmlspecialchars($config['name']) : self::config('credentials.name');
        $password_value = isset($config['value']) ? htmlspecialchars($config['value']) : self::config('credentials.password');

        $val = Request::data($password_name);
        if ($val !== null && $val == $password_value)
            return call_user_func($f);
    }

    public static function startBlock(array $config = []) {
        self::$block_password = [
            'name' => isset($config['name']) ? htmlspecialchars($config['name']) : self::config('credentials.name'),
            'value' => isset($config['value']) ? htmlspecialchars($config['value']) : self::config('credentials.password')
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
        if ($data !== null) return $data;

        $origin = Request::headers('Origin');
        if (!$origin) {
            $referer = Request::headers('Referer');
            $origin = $referer ? (parse_url($referer, PHP_URL_HOST) ?? '') : '';
        }

        return $data = (string)$origin;
    }

    public static function getClientIp(): string {
        static $data = null;
        if ($data !== null) return $data;

        $forwarded = Request::headers('X-Forwarded-For');
        if ($forwarded)
            return $data = trim(explode(',', $forwarded)[0]);

        return $data = (string)(Request::headers('Client-Ip') ?: $_SERVER['REMOTE_ADDR'] ?? '');
    }

    public static function handleCORS($config = []) {
        $allowed_origins = (isset($config['allowed_origins']) && is_array($config['allowed_origins']))
            ? array_filter($config['allowed_origins'], fn($origin) => !empty($origin) && (filter_var($origin, FILTER_VALIDATE_URL) || $origin === '*'))
            : ['*'];

        $forbidden_origins = (isset($config['forbidden_origins']) && is_array($config['forbidden_origins']))
            ? array_filter($config['forbidden_origins'], fn($origin) => !empty($origin) && filter_var($origin, FILTER_VALIDATE_URL))
            : [];

        $allowed_methods = (isset($config['methods']) && is_array($config['methods']))
            ? array_intersect(array_map('strtoupper', $config['methods']), self::config('CORS.methods'))
            : self::config('CORS.methods');

        $allowed_headers = (isset($config['headers']) && is_array($config['headers']))
            ? array_map('htmlspecialchars', $config['headers'])
            : ['Content-Type', 'Authorization', 'X-Requested-With'];

        $request_origin = self::getRequestOrigin();

        if (!empty($request_origin) && in_array($request_origin, $forbidden_origins))
            self::throw(403);

        $origin_header = in_array('*', $allowed_origins)
            ? '*'
            : ((!empty($request_origin) && in_array($request_origin, $allowed_origins))
                ? $request_origin
                : null);

        if ($origin_header === null)
            self::throw(403);

        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Allow-Origin: $origin_header");
        header("Access-Control-Allow-Headers: " . implode(", ", $allowed_headers));

        if (Request::method() === 'OPTIONS') {
            header("Access-Control-Allow-Methods: " . implode(", ", $allowed_methods));
            Response::status(200)->send();
        }
    }

    
}
