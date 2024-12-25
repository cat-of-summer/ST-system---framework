<?php

namespace ST_system;

class Main {

    public static function get_timestamp($format = null) { //"d-m-Y H:i:s"
        $microtime = microtime(true);

        if ($format === null)
            return $microtime;

        $DateTime = new \DateTime();
        $DateTime->setTimestamp((int)$microtime);

        return $DateTime->format($format);
    }
}

class Debug {

    public static $DateTimeFormat = 'd-m-Y H:i:s';
    public static $DateTimeFileFormat = 'd-m-Y~H-i-s';
    public static $dump_method = 'var_dump'; //'var_dump', 'print_r', 'var_export'
    
    private static $dump_call_counter = [];

    private static function get_output($content, $add_tree_backtrace) {
        $timestamp_value = Main::get_timestamp();
        
        ob_start();
        switch (self::$dump_method) {
            case 'print_r':
                print_r($content);
                break;
            case 'var_export':
                var_export($content);
                break;
            default:
                var_dump($content);
                break;
        }
        $output = ob_get_clean();

        if (self::$dump_method == 'var_dump')
            $output = preg_replace('/^(.*?\n){2}/', '', $output, 1);
        
        $DateTime = new \DateTime();
        $DateTime->setTimestamp((int)$timestamp_value);
        
        $timestamp = $DateTime->format(self::$DateTimeFormat).':'.$timestamp_value;
        $backtrace = $add_tree_backtrace ? self::get_backtrace(['skip_start' => 1]) : self::get_backtrace(['skip_start' => 1, 'chain' => false]) ;

        return '<pre>'.PHP_EOL.$timestamp.PHP_EOL.$backtrace.$output.PHP_EOL.'</pre>';
    }
    
    public static function get_backtrace($PARAMS = []) {
        /*
            [
                'chain' => true,
                'skip_start' => 0,
                'skip_end' => 0
            ]
        */

        $get_trace_func = function($trace) {
            $call = isset($trace['class'])
                ? "{$trace['class']}{$trace['type']}{$trace['function']}()"
                : "{$trace['function']}()";

            $file = isset($trace['file']) ? $trace['file'] : '[internal function]';
            $line = isset($trace['line']) ? $trace['line'] : '[unknown line]';

            return "{$call} in {$file} on line {$line}.".PHP_EOL;
        };

        $chain = isset($PARAMS['chain']) ? (bool)$PARAMS['chain'] : true;
        $skip_start = isset($PARAMS['skip_start']) ? (int)$PARAMS['skip_start'] + 1 : 1;
        $skip_end = isset($PARAMS['skip_end']) ? (int)$PARAMS['skip_end'] : 0;

        if ($chain) {
            $full_backtrace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT);

            $result = "";
            for ($level = $skip_start; $level < count($full_backtrace) - $skip_end; $level++) {
                $indent = str_repeat("    ", $level - $skip_start).'↘ ';

                $result .= $indent.$get_trace_func($full_backtrace[$level]);
            }

        } else {
            $last_caller = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT)[2];

            $result = $get_trace_func($last_caller);
        }

        return $result;
    }

    public static function dump_here($content, $PARAMS = []) {
        /*
            [
                'print' => true,
                'add_backtrace' => false
            ]
        */

        $to_print = isset($PARAMS['print']) ? (bool)$PARAMS['print'] : true;
        $add_tree_backtrace_to_content = isset($PARAMS['add_backtrace']) ? (bool)$PARAMS['add_backtrace'] : false;

        $output = self::get_output($content, $add_tree_backtrace_to_content);

        if ($to_print) 
            print $output;
        
        return $output;
    }

    public static function dump_to_file($content, $PARAMS = []) {
        /*
            [
                'file_name' => 'log', //log.txt
                'dir_path' => 'logs',
                'merge_dumps' => true,
                'add_backtrace' => false,
                'add_timestamp' => false,
                'append' => false,
            ]
        */

        $prepare_file_name_func = function ($file_name, $add_timestamp) {
            $last_slash_position = strrpos($file_name, '/');
            $last_dot_position = strrpos($file_name, '.', $last_slash_position);
    
            if (!$last_dot_position)
                $file_name .= '.html';
    
            if ($add_timestamp) {
                $last_dot_position = strrpos($file_name, '.', $last_slash_position);
                $file_name = substr_replace($file_name, '_'.Main::get_timestamp(self::$DateTimeFileFormat).'.', $last_dot_position, 1);
            }
    
            return $file_name;
        };

        $file_name_from_dir_path = isset($PARAMS['file_name']) ? htmlspecialchars($PARAMS['file_name']) : 'log';
        $dir_path_from_document_root = isset($PARAMS['dir_path']) ? htmlspecialchars($PARAMS['dir_path']) : 'logs';
        $merge_dumps_in_one_file = isset($PARAMS['merge_dumps']) ? (bool)$PARAMS['merge_dumps'] : true;
        $add_tree_backtrace_to_content = isset($PARAMS['add_backtrace']) ? (bool)$PARAMS['add_backtrace'] : false;
        $add_timestamp_to_name = isset($PARAMS['add_timestamp']) ? (bool)$PARAMS['add_timestamp'] : false;
        $need_to_append_files = isset($PARAMS['append']) ? (bool)$PARAMS['append'] : false;
    
        $output = self::get_output($content, $add_tree_backtrace_to_content);
        $file_name = $prepare_file_name_func($file_name_from_dir_path, $add_timestamp_to_name);
        $dir_path = $_SERVER["DOCUMENT_ROOT"].'/'.$dir_path_from_document_root;
        $full_path = str_replace('//', '/', $dir_path.'/'.$file_name);

        if (!is_dir($dir_path)) mkdir($dir_path, 0777, true);

        self::$dump_call_counter[$full_path]++;

        $need_to_append_current_file = (self::$dump_call_counter[$full_path] == 1) 
            ? (file_exists($full_path) && $need_to_append_files) 
            : $merge_dumps_in_one_file;

        return file_put_contents($full_path, $output, !$need_to_append_current_file ?: FILE_APPEND);
    }

    public static function dump_to_email($content, $PARAMS = []) {
        /*
            [
                'to' => null,
                'subject' => 'dump_to_email_log',
                'add_backtrace' => false
            ]
        */

        $email_to = isset($PARAMS['to']) ? htmlspecialchars($PARAMS['to']) : null;
        $subject = isset($PARAMS['subject']) ? htmlspecialchars($PARAMS['subject']) : 'dump_to_email_log';
        $add_tree_backtrace_to_content = isset($PARAMS['add_backtrace']) ? (bool)$PARAMS['add_backtrace'] : false;

        $output = self::get_output($content, $add_tree_backtrace_to_content);

        return mail($email_to, $subject, $output);
    }

}

class Access {
        
    private static $DefaultPasswordName = 'pass';
    private static $ExistingTransmissionMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
    
    public static function set_password($PARAMS = []) {
        /*
            [
                'name' => self::$DefaultPasswordName,
                'value' => Main::get_timestamp('dm'),
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
        $password_value = isset($PARAMS['value']) ? htmlspecialchars($PARAMS['value']) : Main::get_timestamp('dm');
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
        echo "Access denied: The origin is not allowed by the CORS policy.";
        exit;
    }
    
    public static function throw_404() {
        header("HTTP/1.1 404 Not Found");
        header("Content-Type: text/plain");
        header("X-Content-Type-Options: nosniff");
        echo "Error 404: The requested resource was not found.";
        exit;
    }

    private static function get_client_origin() {
        return isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    }

    public static function handle_CORS($PARAMS = []) {
        /*
            [
                'allowed_origins' => ['*'], //Например: https://example.com, https://sub.example.com, http://localhost, http://127.0.0.1
                'forbidden_origins' => [], 
                'methods' => self::$ExistingTransmissionMethods,
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
            ? array_intersect(array_map('strtoupper', $PARAMS['methods']), self::$ExistingTransmissionMethods)
            : self::$ExistingTransmissionMethods;

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

        $client_origin = self::get_client_origin();

        if (!empty($client_origin) && in_array($client_origin, $forbidden_origins)) 
            self::throw_403();
        

        if (in_array('*', $allowed_origins))
            header("Access-Control-Allow-Origin: *");
        else {
            
            if (!empty($client_origin) && in_array($client_origin, $allowed_origins)) 
                header("Access-Control-Allow-Origin: $client_origin");
            else 
                self::throw_403();   
        }
        
        header("Access-Control-Allow-Headers: " . implode(", ", $allowed_headers));
    }

    public static function htaccess($PARAMS = []) {

    }
}

class URL_parser {

    private static $URL_parsers_list = [];

    private $Init_RulesHandler;
    private $Init_UrlRules;

    public function __construct($PARAMS = []) {
        /*
            [
                'url_rules' => [],
                 Пример url_rules => [ //Первый парметр, url, потом передача аргумента, третий параметр - приориетность. Возвращаться будут все валидные правила высшего приоритета
                    ['/local/', ['test']],
                    ['/local/test.php', 'value', 1],
                    ['/local/test/test.php', $param, 2]
                 ],
                'rules_handler' => function($PAGE_PARAMS, $URL_PARAMS) {
                    return ['PAGE_PARAMS' => $PAGE_PARAMS, 'URL_PARAMS' => $URL_PARAMS];
                },
                'key' => null //index
            ]
        */

        $this->Init_UrlRules = (isset($PARAMS['url_rules']) && is_array($PARAMS['url_rules']))
            ? $PARAMS['url_rules']
            : [];

        $this->Init_RulesHandler = (isset($PARAMS['rules_handler']) && is_callable($PARAMS['rules_handler']))
            ? $PARAMS['rules_handler']
            : function($URL_PARAMS, $PAGE_PARAMS) {
                return ['URL_PARAMS' => $URL_PARAMS, 'PAGE_PARAMS' => $PAGE_PARAMS];
            };

        if (isset($PARAMS['key']))
            self::$URL_parsers_list[(string)$PARAMS['key']] = $this;
        else
            self::$URL_parsers_list[] = $this;

        return key(end(self::$URL_parsers_list));
    }

    public function get_handler() {
        return $this->Init_RulesHandler;
    }

    public function get_url_rules() {
        return $this->Init_UrlRules;
    }

    public static function apply_parser($key, ...$PAGE_PARAMS) {
        
        if (!isset(self::$URL_parsers_list[$key]))
            return false;

        $url_parser_obj = self::$URL_parsers_list[$key];

        if (empty($url_parser_obj->get_url_rules()))
            return false;

        $request_url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        $URL_PARAMS = [];
        $current_priority = 0;
        foreach ($url_parser_obj->get_url_rules() as $rule_param)
            if (preg_match("#".preg_quote($rule_param[0], '#')."#", $request_url)) {
                if ($current_priority < isset($rule_param[2]) ? (int)$rule_param[2] : 0 ) {
                    $URL_PARAMS = [];
                    $current_priority = (int)$rule_param[2];
                }
                $URL_PARAMS[] = $rule_param[1];
            }
            
        return call_user_func($url_parser_obj->get_handler(), $URL_PARAMS, $PAGE_PARAMS);
            
    }
    
}

class YandexCaptcha {
    private $public_key;
    private $private_key;
    private $render_params_array;

    static private $THROW_ERRORS = true;
    static private $USE_CONSOLE_LOG_IN_DEFAULT_PARAM = false;
    static private $VERIFY_URL = 'https://smartcaptcha.yandexcloud.net/validate';

    static private $IS_CDN_INCLUDED;

    public function __construct($PARAMS = []) {
        /*
            [
                'render_params_array' => [
                    'default' => [ Параметры по умолчанию, собранные при создании конструктора
                        'smart-captcha' => false,
                        'hl' => 'ru',
                        'invisible' => 'false',
                        'onSuccess_JS_func' => function ($container_id) {
                            return "console.log('Успешное прохождения блока {$container_id}')";
                        },
                        'onFail_JS_func' => function ($container_id) {
                            return "console.log('Неудачное прохождения блока {$container_id}')";
                        },
                        'onExpired_JS_func' => function ($container_id) {
                            return "console.log('Истекло время существование для блока {$container_id}')";
                        }
                    ]
                ],
            ]
        */
        if (preg_match('/^[a-zA-Z0-9_-]{20,100}$/', $PARAMS['public_key']))
            $this->public_key = htmlspecialchars($PARAMS['public_key']);
        else
            if (self::$THROW_ERRORS) throw new \Exception('Invalid public key format');
    
        if (preg_match('/^[a-zA-Z0-9_-]{20,100}$/', $PARAMS['private_key']))
            $this->private_key = htmlspecialchars($PARAMS['private_key']);
        else
            if (self::$THROW_ERRORS) throw new \Exception('Invalid private key format');

        $this->render_params_array = array_merge(((isset($PARAMS['render_params_array']) && is_array($PARAMS['render_params_array'])) ? $PARAMS['render_params_array'] : []), [
            'default' => [
                'smart-captcha' => false,
                'hl' => 'ru',
                'invisible' => false,
                'onSuccess_JS_func' => function ($container_id) {
                    return (self::$USE_CONSOLE_LOG_IN_DEFAULT_PARAM) ? "console.log('Успешное прохождения блока {$container_id}')" : '';
                },
                'onFail_JS_func' => function ($container_id) {
                    return (self::$USE_CONSOLE_LOG_IN_DEFAULT_PARAM) ? "console.log('Неудачное прохождения блока {$container_id}')" : '';
                },
                'onExpired_JS_func' => function ($container_id) {
                    return (self::$USE_CONSOLE_LOG_IN_DEFAULT_PARAM) ? "console.log('Истекло время существование для блока {$container_id}')" : '';
                }
            ]
        ]);
    }

    public function connect_CDN($PARAMS = []) {
        /*
            [
                'onload_JS_func' => function () {
                    return "console.log('Успешное подключение CDN!')";
                },
            ]
        */
        if (self::$IS_CDN_INCLUDED)
            return true;

        $onLoad_JS = (isset($PARAMS['onload_JS_func']) && is_callable($PARAMS['onload_JS_func']))
        ? $PARAMS['onload_JS_func']()
        : ((self::$USE_CONSOLE_LOG_IN_DEFAULT_PARAM) 
            ? "console.log('Успешное подключение CDN!')" 
            : '');

        echo "
            <script type='text/javascript' defer>
                if (window.IS_CDN_INCLUDED == undefined) {
                    let script = document.createElement('script');
                    script.src = 'https://smartcaptcha.yandexcloud.net/captcha.js';
                    script.defer = true;
                    document.body.appendChild(script);
                    window.IS_CDN_INCLUDED = false;

                    script.onload = function() {
                        window.IS_CDN_INCLUDED = true;
                        {$onLoad_JS}
                    };
                }
            </script>
        ";
        self::$IS_CDN_INCLUDED = true;
    
        return true;
    }

    public function put_captcha($PARAMS = []) {
        /*
            [
                'render_params_key' => 'default', // Ключ для инициализации параметров по умолчанию, собранных при создании конструктора
                'use_smart_captcha' => false,
                'hl' => 'ru',
                'invisible' => false,
                'onSuccess_JS_func' => function ($container_id) {
                    return "console.log('Успешное прохождения блока {$container_id}')";
                },
                'onFail_JS_func' => function ($container_id) {
                    return "console.log('Неудачное прохождения блока {$container_id}')";
                },
                'onExpired_JS_func' => function ($container_id) {
                    return "console.log('Истекло время существование для блока {$container_id}')";
                }
            ]
        */

        if (empty($this->public_key) || !self::$IS_CDN_INCLUDED)
            return false;

        $container_id = 'captcha_' . md5(microtime(true) . rand());

        $render_params_key = isset($PARAMS['render_params_key']) ? htmlspecialchars($PARAMS['render_params_key']) : 'default';
        $RENDER_PARAMS = array_merge($this->render_params_array[$render_params_key], $PARAMS);
        
        $use_smart_captcha = ($RENDER_PARAMS['use_smart_captcha'] === true);
        $lang =  htmlspecialchars($RENDER_PARAMS['hl']);
        $is_invisible = (bool)$RENDER_PARAMS['invisible'] ? 'true' : 'false';

        $onSuccess_JS = $RENDER_PARAMS['onSuccess_JS_func']($container_id);
        $onFail_JS = $RENDER_PARAMS['onFail_JS_func']($container_id);
        $onExpired_JS = $RENDER_PARAMS['onExpired_JS_func']($container_id);

        if ($use_smart_captcha)
            echo "<div id='{$container_id}' class='smart-captcha' data-sitekey='{$this->public_key}'></div>";
        else
            echo "
                <div id='{$container_id}'></div>
                <script type='text/javascript' defer>
                    document.addEventListener('DOMContentLoaded', function() {
                        if (window.IS_CDN_INCLUDED !== undefined) {
                            var check_CDN = setInterval(function() {
                                if (window.IS_CDN_INCLUDED == true) {
                                    clearInterval(check_CDN);
                                    var captcha_container = document.getElementById('{$container_id}');
                                    captcha_container.setAttribute('is_invalid', '');
                                    captcha_container.removeAttribute('is_valid');

                                    window.smartCaptcha.render(captcha_container, {
                                        sitekey: '{$this->public_key}',
                                        hl: '{$lang}',
                                        invisible: {$is_invisible},
                                        callback: function(response) {
                                            if (response) {
                                                captcha_container.setAttribute('is_valid', '');
                                                captcha_container.removeAttribute('is_invalid');
                                                {$onSuccess_JS}
                                            } else {
                                                {$onFail_JS}
                                            }
                                        },
                                        onFail: function() {
                                            {$onFail_JS}
                                        },
                                        onExpired: function() {
                                            {$onExpired_JS}
                                        }
                                    });
                                }
                            }, 100);
                        }
                    });
                </script>
            ";

        return $container_id;
    }

    public function check_captcha($captcha_code) {
        $curl = curl_init(self::$VERIFY_URL);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 1); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query([
            'token' => $captcha_code,
            'secret' => $this->private_key,
        ]));

        $curl_response = curl_exec($curl);

        if (curl_errno($curl)) {
            $error = curl_error($curl);
            curl_close($curl);
            
            if (self::$THROW_ERRORS) 
                throw new \Exception("cURL error: $error");
            else
                return false;
        }
    
        $code_response = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $result = json_decode($curl_response);

        if ($code_response === 200 && $result->status === "ok")
            return true;
        
        if (self::$THROW_ERRORS) 
            throw new \Exception("Captcha verification failed. $curl_response");
        else
            return false;
    }
}