<?php

namespace ST_system;

use ST_system\Traits\HasConfig;
use ST_system\HTTP\Request;
use ST_system\HTTP\Response;

final class Access {

    use HasConfig;

    protected static function getDefaultConfig(): array {
        return [
            'password_name' => 'pass',
            'request_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH']
        ];
    }

    private static $block_password = [
        'name' => null,
        'value' => null
    ];

    public static function requestAccess($PARAMS = []) {
        $password_name = isset($PARAMS['name']) ? htmlspecialchars($PARAMS['name']) : self::config('password_name');
        $password_value = isset($PARAMS['value']) ? htmlspecialchars($PARAMS['value']) : date('dm');
        
        $onFail_func = (($PARAMS['onFail'] ?? null) instanceof \Closure)
            ? $PARAMS['onFail']
            : function () { header("Location: /"); exit; };

        $onSuccess_func = (($PARAMS['onSuccess'] ?? null) instanceof \Closure)
            ? $PARAMS['onSuccess']
            : fn() => true;

        $val = Request::data($password_name);
        return ($val === null || $val != $password_value)
            ? $onFail_func()
            : $onSuccess_func();
    }

    public static function httpAccess(array $PARAMS = []) {
        $login = isset($PARAMS['login']) ? htmlspecialchars($PARAMS['login']) : self::config('password_name');
        $password = isset($PARAMS['password']) ? htmlspecialchars($PARAMS['password']) : date('dm');

        if (
            !isset($_SERVER['PHP_AUTH_USER']) ||
            !isset($_SERVER['PHP_AUTH_PW']) ||
            $_SERVER['PHP_AUTH_USER'] !== $login ||
            $_SERVER['PHP_AUTH_PW'] !== $password
        ) {
            Response::status(401)->header('WWW-Authenticate', 'Basic realm="Restricted Area"')->send();
        }
    }

    public static function call(callable $f, array $PARAMS = []) {
        $password_name = isset($PARAMS['name']) ? htmlspecialchars($PARAMS['name']) : self::config('password_name');
        $password_value = isset($PARAMS['value']) ? htmlspecialchars($PARAMS['value']) : date('dm');

        $val = Request::data($password_name);
        if ($val !== null && $val == $password_value)
            return call_user_func($f);
    }

    public static function startBlock(array $PARAMS = []) {
        self::$block_password = [
            'name' => isset($PARAMS['name']) ? htmlspecialchars($PARAMS['name']) : self::config('password_name'),
            'value' => isset($PARAMS['value']) ? htmlspecialchars($PARAMS['value']) : date('dm')
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

    public static function throw_403() {
        Response::text('', 403)->header('X-Content-Type-Options', 'nosniff')->send();
    }

    public static function throw_404() {
        Response::text('', 404)->header('X-Content-Type-Options', 'nosniff')->send();
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

    public static function handleCORS($PARAMS = []) {
        $allowed_origins = (isset($PARAMS['allowed_origins']) && is_array($PARAMS['allowed_origins']))
            ? array_filter($PARAMS['allowed_origins'], fn($origin) => !empty($origin) && (filter_var($origin, FILTER_VALIDATE_URL) || $origin === '*'))
            : ['*'];

        $forbidden_origins = (isset($PARAMS['forbidden_origins']) && is_array($PARAMS['forbidden_origins']))
            ? array_filter($PARAMS['forbidden_origins'], fn($origin) => !empty($origin) && filter_var($origin, FILTER_VALIDATE_URL))
            : [];

        $allowed_methods = (isset($PARAMS['methods']) && is_array($PARAMS['methods']))
            ? array_intersect(array_map('strtoupper', $PARAMS['methods']), self::config('request_methods'))
            : self::config('request_methods');

        $allowed_headers = (isset($PARAMS['headers']) && is_array($PARAMS['headers']))
            ? array_map('htmlspecialchars', $PARAMS['headers'])
            : ['Content-Type', 'Authorization', 'X-Requested-With'];

        $request_origin = self::getRequestOrigin();

        if (!empty($request_origin) && in_array($request_origin, $forbidden_origins))
            self::throw_403();

        $origin_header = in_array('*', $allowed_origins)
            ? '*'
            : ((!empty($request_origin) && in_array($request_origin, $allowed_origins))
                ? $request_origin
                : null);

        if ($origin_header === null)
            self::throw_403();

        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Allow-Origin: $origin_header");
        header("Access-Control-Allow-Headers: " . implode(", ", $allowed_headers));

        if (Request::method() === 'OPTIONS') {
            header("Access-Control-Allow-Methods: " . implode(", ", $allowed_methods));
            Response::status(200)->send();
        }
    }

    // public static function proactiefFilter(array $config = []) {
    //     $config['timeout'];
    //     $config['ban_time'];
    // }

    // /** @param mixed $ips */
    // public static function banIp($ips) { //Диапазон, массив, строка
    //     $config['ban_time'];
    // }

    // /** @param mixed $ips */
    // public static function unbanIp($ips) { //Диапазон, массив, строка
    // }

}
