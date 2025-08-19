<?php

namespace ST_system\API;

class Router {

    private static $URL_parsers_list = [];
    public static $methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'];

    private $RulesHandler;
    private $UrlRules;

    private $params = [
        'apply_once' => false,
        'strict_mode' => false,
        'point' => '/',
        'methods' => []
    ];
 
    public function __construct($PARAMS = []) {
        /*
            [
                'url_rules' => [],
                 Пример url_rules => [ //Первый парметр, url, потом передача аргумента, третий параметр - приориетность. Возвращаться будут все валидные правила высшего приоритета
                    ['/local/', ['test']],
                    ['/local/test.php', 'value', 1],
                    ['/local/test/test.php', $param, 2]
                 ],
                'apply_once' => false, 'Если несколько валидных правил, будешь лишь первое применяться'
                'strict_mode' => false, 'Если false, то ищет в url подстроку с правилом, если true, то совпадение ^...&'
                'rules_handler' => function($PARSER_PARAMS, $URL_PARAMS, $PAGE_PARAMS) {
                return ['PARSER_PARAMS' => $PARSER_PARAMS, 'URL_PARAMS' => $URL_PARAMS, 'PAGE_PARAMS' => $PAGE_PARAMS];
            },
                'key' => null //index,
            ]
        */
        


        $this->UrlRules = (isset($PARAMS['url_rules']) && is_array($PARAMS['url_rules']))
            ? $PARAMS['url_rules']
            : [];

        $this->RulesHandler = (isset($PARAMS['rules_handler']) && is_callable($PARAMS['rules_handler']))
            ? function () use ($PARAMS) {
                return call_user_func_array($PARAMS['rules_handler'], func_get_args());
            }
            : function($PARSER_PARAMS, $URL_PARAMS, $PAGE_PARAMS) {
                return ['PARSER_PARAMS' => $PARSER_PARAMS, 'URL_PARAMS' => $URL_PARAMS, 'PAGE_PARAMS' => $PAGE_PARAMS];
            };

        if (isset($PARAMS['key']))
            self::$URL_parsers_list[(string)$PARAMS['key']] = $this;
        else
            self::$URL_parsers_list[] = $this;

        foreach ($this->params as $key => &$value)
            if (isset($PARAMS[$key]) && !in_array($key, ['methods']))
                switch (gettype($value)) {
                    case 'boolean': $value = (bool)$PARAMS[$key]; break;
                    case 'integer': $value = (int)$PARAMS[$key]; break;
                    case 'double':  $value = (float)$PARAMS[$key]; break;
                    case 'string':  $value = (string)$PARAMS[$key]; break;
                    case 'array':   $value = (array)$PARAMS[$key]; break;
                    case 'object':  $value = (object)$PARAMS[$key]; break;
                    case 'NULL':    $value = null; break;
                    default:        throw new \Exception("Не поддерживаемый тип данных ".gettype($value)." параметра {$key}");
                }
        
        $this->params['methods'] = (isset($PARAMS['methods']) && is_array($PARAMS['methods']))
            ? array_intersect_key($PARAMS['methods'], self::$methods)
            : self::$methods;
    }

    public static function apply_parser($key, ...$PAGE_PARAMS) {
        
        if (!isset(self::$URL_parsers_list[$key]))
            return null;

        $url_parser_obj = self::$URL_parsers_list[$key];

        if (empty($url_parser_obj->UrlRules))
            return null;

        return $url_parser_obj->apply(...$PAGE_PARAMS);
    }
    
    private function get_regexp($pattern) {
        $url_params = [];

        $pattern = preg_quote($pattern, '#');

        if (!$this->params['strict_mode'])
            $pattern = '/?'.rtrim(ltrim($pattern, '/'), '/').'/?';

        $regexp = str_replace('//', '/', '#'.($this->params['strict_mode'] ? "^{$this->params['point']}" : '').preg_replace_callback('#\\\\\{(\w+)\\\\\}#', function ($matches) use (&$url_params) {
            $url_params[] = $matches[1];
            return "(?P<{$matches[1]}>[^/]+)";
        }, $pattern).($this->params['strict_mode'] ? '$' : '').'#');

        return ['string' => $regexp, 'url_params' => $url_params];
    }

    public function apply(...$PAGE_PARAMS) {
        $request_url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        if (!in_array($_SERVER['REQUEST_METHOD'], $this->params['methods']))
            throw new \Exception("Метод {$_SERVER['REQUEST_METHOD']} не доступен");
        
        $PARSER_PARAMS = [];
        $max_priority = 0;
        $rules_counter = 0;

        foreach ($this->UrlRules as $rule_param) {

            $regexp = $this->get_regexp($rule_param[0]);

            if (preg_match($regexp['string'], $request_url, $matches)) {
                if ($max_priority < isset($rule_param[2]) ? (int)$rule_param[2] : 0 ) {
                    $PARSER_PARAMS = [];
                    $URL_PARAMS = [];
                    $rules_counter = 0;
                    $max_priority = (int)$rule_param[2];
                }

                if ($this->params['apply_once'] && $rules_counter > 0) continue;
    
                $PARSER_PARAMS[] = $rule_param[1];
                $URL_PARAMS[] = array_intersect_key($matches, array_flip($regexp['url_params']));

                $rules_counter++;

            }
        }
        
        return call_user_func($this->RulesHandler, $PARSER_PARAMS, $URL_PARAMS, $PAGE_PARAMS);
    }

    public function __get($name) {
        if (isset($this->params[$name]))
            return $this->params[$name];

        throw new \Exception("Свойство '{$name}' не существует.");
    }
}