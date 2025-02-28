<?php

namespace ST_system;

class Access {
        
    private static $DefaultPasswordName = 'pass';
    private static $ExistingTransmissionMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
    
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

    public static function htaccess($PARAMS = []) {
        
    }
}

class URL_parser {

    private static $URL_parsers_list = [];

    private $RulesHandler;
    private $UrlRules;

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

        $this->UrlRules = (isset($PARAMS['url_rules']) && is_array($PARAMS['url_rules']))
            ? $PARAMS['url_rules']
            : [];

        $this->RulesHandler = (isset($PARAMS['rules_handler']) && is_callable($PARAMS['rules_handler']))
            ? $PARAMS['rules_handler']
            : function($URL_PARAMS, $PAGE_PARAMS) {
                return ['URL_PARAMS' => $URL_PARAMS, 'PAGE_PARAMS' => $PAGE_PARAMS];
            };

        if (isset($PARAMS['key']))
            self::$URL_parsers_list[(string)$PARAMS['key']] = $this;
        else
            self::$URL_parsers_list[] = $this;
    }

    public static function apply_parser($key, ...$PAGE_PARAMS) {
        
        if (!isset(self::$URL_parsers_list[$key]))
            return null;

        $url_parser_obj = self::$URL_parsers_list[$key];

        if (empty($url_parser_obj->UrlRules))
            return null;

        return $url_parser_obj->apply(...$PAGE_PARAMS);
    }
    
    public function apply(...$PAGE_PARAMS) {
        $request_url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        $URL_PARAMS = [];
        $max_priority = 0;

        foreach ($this->UrlRules as $rule_param)
            if (preg_match("#".preg_quote($rule_param[0], '#')."#", $request_url)) {
                if ($max_priority < isset($rule_param[2]) ? (int)$rule_param[2] : 0 ) {
                    $URL_PARAMS = [];
                    $max_priority = (int)$rule_param[2];
                }
                $URL_PARAMS[] = $rule_param[1];
            }
            
        return call_user_func($this->RulesHandler, $URL_PARAMS, $PAGE_PARAMS);
    }
}

//Необходимо поддержку многих свойств сделать помимо value
class MarkupProperty {
    private static $valid_datatypes = [
        'string' => ['is_string'],
        'int' => ['is_int'],
        'bool' => ['is_bool'],
        'date' => ['strtotime'],
        'url' => ['filter_var', FILTER_VALIDATE_URL],
        'email' => ['filter_var', FILTER_VALIDATE_EMAIL],
        'property' => ['is_object'],
        'array' => ['is_array']
    ];

    private $datatype;
    private $value;
    private $is_required;
    
    public function __construct($value, $datatype = 'string', $is_required = false) {
        $this->is_required = $is_required;

        if (!isset(self::$valid_datatypes[$datatype]))
            throw new \InvalidArgumentException("Тип данных {$datatype} не поддерживается.");
        
        $this->datatype = $datatype;

        $this->__set('value', $value);
    }

    public function __set($key, $value) {
        if (!in_array($key, ['value'])) 
            throw new \LogicException("Попытка доступа к несуществующему свойству '{$key}'.");

        if ($this->is_required && empty($value))
            throw new \LogicException("Передано пустое значение обязательного свойства.");

        if (!call_user_func(self::$valid_datatypes[$this->datatype][0], $value, ...array_slice(self::$valid_datatypes[$this->datatype], 1)) ||
            (($this->datatype == 'property') && (!($value instanceof MarkupProperty)))
        )
            throw new \InvalidArgumentException("При записи свойства, ожидаемый тип данных '{$this->datatype}' не совпал с полученным.");

        $this->value = $value;
    }

    public function __get($key) {
        if (!in_array($key, ['value', 'is_required'])) 
            throw new \LogicException("Попытка доступа к несуществующему свойству '{$key}'.");
        
        return $this->$key;
    }
}

class Markup {

    public static $vocabularies = [
        'schema.org' => [
            'name' => 'https://schema.org',
            'types' => [
                'Thing' => [
                    'description' => ['string'],
                    'url' => ['url'],
                    'image' => [
                        'src' => ['string'],
                        'alt' => ['string']
                    ],
                    'name' => ['string', true]
                ]
            ],
            'methods' => [
                'JSON-LD' => [
                    'structure' => '
                        <script type="application/ld+json">
                            "@context": "#n",
                            "@type": "#t",
                            #pv
                        </script>
                    ', 
                    'properties' => '"#p": "#v",'
                ], 
                'RDFa' => []
            ]
        ],
        'Twitter Cards' => []
    ];

    private $vocabulary;
    private $type;
    private $method;
    public $markup_data; //Надо сделать приватным!

    public function __construct($vocabulary, $type, $markup_data = [], $method = null) {

        if (!isset(self::$vocabularies[$vocabulary]))
            throw new \InvalidArgumentException("Словарь {$vocabulary} не найден.");

        $this->vocabulary = $vocabulary;

        if (!isset(self::$vocabularies[$vocabulary]['types'][$type]))
            throw new \InvalidArgumentException("Тип {$type} не найден в словаре {$vocabulary}.");
    
        $this->type = $type;

        if ($method === null) {
            $default_methods = array_keys(self::$vocabularies[$vocabulary]['methods']);
        
            if (empty($default_methods))
                throw new \InvalidArgumentException("В словаре {$vocabulary} отсутствуют доступные методы.");

            $this->method = $default_methods[0];
            
        } elseif (!isset(self::$vocabularies[$vocabulary]['methods'][$method]))
                throw new \InvalidArgumentException("Метод {$method} не найден в словаре {$vocabulary}.");
        else
            $this->method = $method;
                
        if (!empty($markup_data) && is_array($markup_data))
            $this->set_properties($markup_data);
    }

    public function set_properties($markup_data) {
        $recursive_func = function(array $structure, array $values) use (&$recursive_func) {
            $result = [];
            $is_required = false;
            
            foreach ($structure as $key => $value)
                if (is_array($value) && isset($value[0]) && is_string($value[0])) {

                    try {
                        $result[$key] = new MarkupProperty($values[$key], (string)$value[0], (bool)$value[1]);
                    } catch (\Exception $e) {
                        throw new \LogicException("Ошибка при обработке сохранении свойства '{$key}'. ".$e->getMessage());
                    }
                    $is_required = $is_required || (bool)$value[1];
                } elseif (is_array($value)) {
                    $nested = $recursive_func($value, $values[$key] ?? []);
                    $is_required = $is_required || $nested[1];
                    $result[$key] = new MarkupProperty($nested[0], 'array', $is_required);
                } else
                    throw new \LogicException("Некорректная структура типа для ключа '{$key}'.");
        
            return [$result, $is_required];
        };

        $this->markup_data = new MarkupProperty($recursive_func(self::$vocabularies[$this->vocabulary]['types'][$this->type], $markup_data)[0], 'array', true);
    }

    public function render() {
        $recursive_func = function($value) use (&$recursive_func) {
            if ($value instanceof MarkupProperty)
                return $recursive_func($value->value);
            elseif (is_array($value)) {
                $result = [];
                foreach ($value as $key => $item)
                    $result[$key] = $recursive_func($item);

                return $result;
            } else
                return $value;
        };

        $jsonLd = [
            "@context" => "$this->vocabulary",
            "@type" => "$this->type"
        ];

        foreach ($this->markup_data->value as $key => $property) {
            $jsonLd[$key] = $recursive_func($property->value);
        }

        return json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }


}