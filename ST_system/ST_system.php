<?php

namespace ST_system;

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