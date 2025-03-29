<?php

namespace ST_system;

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