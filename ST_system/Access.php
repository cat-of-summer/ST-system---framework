<?php

namespace ST_system;

use ST_system\Traits\HasConfig;
use ST_system\Traits\HasValidatableParams;

final class Access {

    use HasConfig;
    use HasValidatableParams;

    private static array $CONFIG = [
        'password_name' => 'pass',
        'request_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH']
    ];
    
    private const config = [
        'password_name' => 'pass',
        'request_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH']
    ];

    private static $block_password = [
        'name' => null,
        'value' => null
    ];
    
    public static function request_access($PARAMS = []) {
        /*
            [
                'name' => self::config['password_name'],
                'value' => date('dm'),
                'onFail' => function () {
                    header("Location: /");
                    exit;
                },
                'onSuccess' => function () {
                    return true;
                }
            ]
        */

        $password_name = isset($PARAMS['name']) ? htmlspecialchars($PARAMS['name']) : self::config['password_name'];
        $password_value = isset($PARAMS['value']) ? htmlspecialchars($PARAMS['value']) : date('dm');
        $onFail_func = (isset($PARAMS['onFail']) && is_callable($PARAMS['onFail'])) 
            ? $PARAMS['onFail'] 
            : function () { header("Location: /"); exit; };

        $onSuccess_func = (isset($PARAMS['onSuccess']) && is_callable($PARAMS['onSuccess'])) 
            ? $PARAMS['onSuccess'] 
            : fn() => true;

        return !isset($_REQUEST[$password_name]) || ($_REQUEST[$password_name] != $password_value)
            ? $onFail_func()
            : $onSuccess_func();
    }

    public static function http_access(array $PARAMS = []) {
        /*
            [
                'login' => self::config['password_name'],
                'password' => date('dm'),
            ]
        */

        $login = isset($PARAMS['login']) ? htmlspecialchars($PARAMS['login']) : self::config['password_name'];
        $password = isset($PARAMS['password']) ? htmlspecialchars($PARAMS['password']) : date('dm');
        
        if (
            !isset($_SERVER['PHP_AUTH_USER']) ||
            !isset($_SERVER['PHP_AUTH_PW']) ||
            $_SERVER['PHP_AUTH_USER'] !== $login ||
            $_SERVER['PHP_AUTH_PW'] !== $password
        ) {
            header('WWW-Authenticate: Basic realm="Restricted Area"');
            header('HTTP/1.0 401 Unauthorized');
            exit;
        }
    }

    public static function call(callable $f, array $PARAMS = []) {
        $password_name = isset($PARAMS['name']) ? htmlspecialchars($PARAMS['name']) : self::config['password_name'];
        $password_value = isset($PARAMS['value']) ? htmlspecialchars($PARAMS['value']) : date('dm');

        if (isset($_REQUEST[$password_name]) && ($_REQUEST[$password_name] == $password_value))
            return call_user_func($f);
    }

    public static function start_block(array $PARAMS = []) {
        /*
            [
                'name' => self::config['password_name'],
                'value' => date('dm'),
            ]
        */

        self::$block_password = [
            'name' => isset($PARAMS['name']) ? htmlspecialchars($PARAMS['name']) : self::config['password_name'],
            'value' => isset($PARAMS['value']) ? htmlspecialchars($PARAMS['value']) : date('dm')
        ];

        ob_start();
    }

    public static function end_block() {

        if (!self::$block_password['name'] || !self::$block_password['value']) return;

        $content = ob_get_clean();

        if (isset($_REQUEST[self::$block_password['name']]) && ($_REQUEST[self::$block_password['name']] == self::$block_password['value']))
            echo $content;
    }

    public static function throw_403() {
        header("HTTP/1.1 403 Forbidden");
        header("Content-Type: text/plain");
        header("X-Content-Type-Options: nosniff");
        exit;
    }
    
    public static function throw_404() {
        header("HTTP/1.1 404 Not Found");
        header("Content-Type: text/plain");
        header("X-Content-Type-Options: nosniff");
        exit;
    }

    public static function get_request_origin() {
        return isset($_SERVER['HTTP_ORIGIN'])
            ? $_SERVER['HTTP_ORIGIN']
            : (function_exists('getallheaders') && isset(getallheaders()['Origin'])
                ? getallheaders()['Origin']
                : (function_exists('apache_request_headers') && isset(apache_request_headers()['Origin'])
                    ? apache_request_headers()['Origin']
                    : (isset($_SERVER['HTTP_REFERER'])
                        ? parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST)
                        : ""
                    )
                )
            );
    }

    public static function get_client_ip() {
        return isset($_SERVER['HTTP_X_FORWARDED_FOR'])
            ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]
            : (isset($_SERVER['REMOTE_ADDR'])
                ? $_SERVER['REMOTE_ADDR']
                : (isset($_SERVER['HTTP_CLIENT_IP'])
                    ? $_SERVER['HTTP_CLIENT_IP'] 
                    : ""
                )
            );
    }

    public static function handle_CORS($PARAMS = []) {
        /*
            [
                'allowed_origins' => ['*'], //Например: https://example.com, https://sub.example.com, http://localhost, http://127.0.0.1
                'forbidden_origins' => [], 
                'methods' => self::config['request_methods'],
                'headers' => ['Content-Type', 'Authorization', 'X-Requested-With']
            ]
        */
        
        $allowed_origins = (isset($PARAMS['allowed_origins']) && is_array($PARAMS['allowed_origins'])) 
            ? array_filter($PARAMS['allowed_origins'], fn($origin) => !empty($origin) && (filter_var($origin, FILTER_VALIDATE_URL) || $origin === '*'))
            : ['*'];
        
        $forbidden_origins = (isset($PARAMS['forbidden_origins']) && is_array($PARAMS['forbidden_origins'])) 
            ? array_filter($PARAMS['forbidden_origins'], fn($origin) => !empty($origin) && filter_var($origin, FILTER_VALIDATE_URL))
            : [];

        $allowed_methods = (isset($PARAMS['methods']) && is_array($PARAMS['methods'])) 
            ? array_intersect(array_map('strtoupper', $PARAMS['methods']), self::config['request_methods'])
            : self::config['request_methods'];

        $allowed_headers = (isset($PARAMS['headers']) && is_array($PARAMS['headers'])) 
            ? array_map('htmlspecialchars', $PARAMS['headers']) 
            : ['Content-Type', 'Authorization', 'X-Requested-With'];
        
        header("Access-Control-Allow-Credentials: true");
    
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            header("Access-Control-Allow-Methods: " . implode(", ", $allowed_methods));
            header("Access-Control-Allow-Headers: " . implode(", ", $allowed_headers));
            header("HTTP/1.1 200 OK");
            exit;
        }

        $request_origin = self::get_request_origin();

        if (!empty($request_origin) && in_array($request_origin, $forbidden_origins)) 
            self::throw_403();
        

        if (in_array('*', $allowed_origins))
            header("Access-Control-Allow-Origin: *");
        else {
            
            if (!empty($request_origin) && in_array($request_origin, $allowed_origins)) 
                header("Access-Control-Allow-Origin: $request_origin");
            else 
                self::throw_403();   
        }
        
        header("Access-Control-Allow-Headers: " . implode(", ", $allowed_headers));
    }

    public static function proactief_filter(array $config = []) {
        $config['timeout'];
        $config['ban_time'];
    }

    public static function ban_ip(mixed $ips) { //Диапазон, массив, строка
        $config['ban_time'];
    }

    public static function unban_ip(mixed $ips) { //Диапазон, массив, строка
    }

}