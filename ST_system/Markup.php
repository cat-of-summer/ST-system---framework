<?php

namespace ST_system;

class Markup {
 
    private static $valid_datatypes = [
        'string' => ['is_string'],
        'int' => ['is_int'],
        'bool' => ['is_bool'],
        'date' => ['strtotime'],
        'url' => ['filter_var', FILTER_VALIDATE_URL],
        'email' => ['filter_var', FILTER_VALIDATE_EMAIL],
    ];

    /*
        У микроразметки есть массив свойств. Свойства могут быть либо скалярными (из списка валидируемых), либо объектом микроразметки.
        У свойств микроразметки 3 параметра:
            [
                0 => []
                1 => (bool) Является ли свойство обязательным
                2 => (bool) Является ли свойство множественным (передавать нужно массив)
            ]
        Первый параметр свойства - массив возможных значений, которое может быть записано в свойство. Использоваться будет то подсвойство, которое удалось записать в свойство.
        Каждое подсвойство представляет из себя либо массив атрибутов, либо другую микроразметку, например:
            [
                'src' => ['url', true],
                'alt' => ['string', false]
            ]
        Ключ - имя атрибута. При создании json_ld будет использоваться первый атрибут. Значение атрибута - массив, в котором первый параметр - тип данных, а второй - является ли атрибут обязательным. По умолчанию true.
        В случае вложенной микроразметки: ['@PostalAddress', true]
    */
    
    private static $vocabularies = [
        'schema.org' => [
            'context' => 'https://schema.org',
            'types' => [
                '@PostalAddress' => [
                    'addressCountry' => [
                        [
                            [['string']]
                        ]
                    ],
                    'addressLocality' => [
                        [
                            [['string']]
                        ]
                    ],
                    'addressRegion' => [
                        [
                            [['string']]
                        ],
                    ],
                    'postOfficeBoxNumber' => [
                        [
                            [['string']]
                        ]
                    ],
                    'postalCode' => [
                        [
                            [['string']]
                        ],
                    ],
                    'streetAddress' => [
                        [
                            [['string']]
                        ],
                    ]
                ],
                '@Person' => [
                    'name' => [
                        [[['string', true]]],
                        true, false
                    ],
                    'age' => [
                        [[['int', true]]]
                    ],
                    'jobTitle' => [
                        [[['string', true]]],
                        false, false
                    ],
                    'image' => [
                        [
                            [
                                'src' => ['url', true], 
                                'alt' => ['string', false]
                            ]
                        ],
                        true, false
                    ],
                    'url' => [
                        [[['url', true]]]
                    ],
                    'sameAs' => [
                        [[['url', true]]],
                        false, true
                    ],
                    'birthDate' => [
                        [[['date', true]]],
                        false, false
                    ],
                    'address' => [
                        [
                            ['@PostalAddress'], 
                            [['string']]
                        ],
                        false, false
                    ],
                    'email' => [
                        [['href' => ['email', true]]],
                        false, false
                    ],
                    'telephone' => [
                        [['href' => ['string', true]]],
                        false, false
                    ]
                ],
                '@ContactPoint' => [
                    'telephone' => [
                        [[['string']]]
                    ],
                    'contactType' => [
                        [[['string']]]
                    ],
                ],
                '@Organization' => [
                    'name' => [
                        [[['string']]],
                        true
                    ],
                    'url' => [
                        [[['url']]]
                    ],
                    'logo' => [
                        [[
                            'src' => ['url'], 
                            'alt' => ['string', false]
                        ]]
                    ],
                    'image' => [
                        [[
                            'src' => ['url'], 
                            'alt' => ['string', false]
                        ]]
                    ],
                    'description' => [
                        [[['string']]]
                    ],
                    'contactPoint' => [
                        [['@ContactPoint']],
                    ],
                    'address' => [
                        [
                            ['@PostalAddress'], 
                            [['string']]
                        ],
                        false, true
                    ],
                ],
                '@MedicalProcedure' => [
                    'name' => [
                        [[['string']]],
                        true
                    ],
                    'url' => [
                        [[['url']]]
                    ],
                    'description' => [
                        [[['string']]]
                    ],
                ]
            ],
        ],
    ];

    private static $render_funcs_map = [];

    public static function set_render_map($render_map) {
        foreach ($render_map as $vocabulary => $types) {
            if (!isset(self::$vocabularies[$vocabulary]))
                throw new \InvalidArgumentException("Словарь {$vocabulary} не найден.");

            foreach ($types as $type => $render_funcs_map) {
                if (!isset(self::$vocabularies[$vocabulary]['types'][$type]))
                    throw new \InvalidArgumentException("Микроразметка {$type} не найдена в словаре {$vocabulary}.");

                if (
                    !is_array($render_funcs_map) 
                    || in_array(false, array_map(
                        fn($render_func) => is_callable($render_func), $render_funcs_map
                    ), true)
                )
                    throw new \InvalidArgumentException("Не передан массив рендер-функций.");
            }
        }

        self::$render_funcs_map = $render_map;
    }

    public static function help($vocabulary_name = null, $type_name = null, $only_required_itemprops = false) {

        $offset = function ($i) {
            return str_repeat('   ', $i);
        };
        
        echo '<pre>';
        foreach (
            isset(self::$vocabularies[$vocabulary_name])
                ? [$vocabulary_name => self::$vocabularies[$vocabulary_name]]
                : self::$vocabularies
        as $vocabulary_name => $vocabulary) {
            echo PHP_EOL."Словарь <b>{$vocabulary_name}</b>:".PHP_EOL;
            foreach (
                isset($vocabulary['types'][$type_name])
                    ? [$type_name => $vocabulary['types'][$type_name]]
                    : $vocabulary['types']
            as $type_name => $type) {
                echo $offset(1)."Микроразметка <b>{$type_name}</b>:".PHP_EOL;
                foreach ($type as $itemprop_name => $itemprop) {
                    if ($only_required_itemprops && (!isset($itemprop[1]) || !$itemprop[1]))
                        continue;

                    echo $offset(2)."Свойство <b>{$itemprop_name}</b>:".PHP_EOL.$offset(3).'Является '.(isset($itemprop[1]) && $itemprop[1] ? 'обязательным' : 'необязательным').' и '.(isset($itemprop[2]) && $itemprop[2] ? 'множественным' : 'одиночным').'.'.PHP_EOL;
                    echo $offset(3).'Возможные значения свойства:'.PHP_EOL;
                    foreach ($itemprop[0] as $property_option) {
                        echo (is_array($property_option) &&
                            !in_array(false, array_map(function($element) {
                                return !is_array($element);
                            }, $property_option)) &&
                            $property_option[0][0] == '@'
                                ? $offset(4)."Объект микроразметки {$property_option[0]}".PHP_EOL
                                : $offset(4).'Список атрибутов:'.PHP_EOL.$offset(5).implode(PHP_EOL.$offset(5), array_map(
                                    fn($key, $value) => (isset($value[1]) && $value[1] ? 'Обязательный атрибут ' : 'Атрибут ')."'<b>".($key === 0 ? 'value' : $key)."</b>' типа <i>{$value[0]}</i>.",
                                    array_keys($property_option),
                                    $property_option
                                )).PHP_EOL
                        );
                    }
                }
                echo PHP_EOL;
            }
            echo PHP_EOL;
        }
        echo '</pre>';
    }

    private $vocabulary;
    private $type;
    private $data;
    private $data_array;
    private $prototype;

    private $parent_property_name;
    private $render_output = [
        'html' => [],
        'json_ld' => null
    ];

    public function __construct($vocabulary, $type, $data = null) {
        if (!isset(self::$vocabularies[$vocabulary]))
            throw new \InvalidArgumentException("Словарь {$vocabulary} не найден.");

        $this->vocabulary = $vocabulary;

        if (!isset(self::$vocabularies[$vocabulary]['types'][$type]))
            throw new \InvalidArgumentException("Микроразметка {$type} не найдена в словаре {$vocabulary}.");
    
        $this->type = $type;
                
        if (!empty($data))
            $this->set_data($data);
    }
    
    public function is_markup() {
        return true;
    }

    public function set_data($data) {

        $this->render_output = [
            'html' => [],
            'json_ld' => null
        ];

        if (!is_array($data))
            throw new \InvalidArgumentException("Попытка вложить некорректные данные в словарь {$this->vocabulary} микроразметки {$this->type}.");

        $errors = [];
        $new_data = [];
        $new_data_array = [];
        $prototype = [];

        $prepare_property = function ($data, $itemprop, $itemprop_name) {
            $property = [];
            $errors = [];

            foreach ($itemprop[0] as $attribute_name => $attribute) {

                if (count($itemprop[0]) == 1) {
                    if ($attribute_name === 0)
                        $attribute_name = 'value';

                    if (isset($data[0]))
                        $data[$attribute_name] = $data[0];
                }

                if (!isset(self::$valid_datatypes[$attribute[0]]))
                    $errors[] = "Тип данных {$attribute[0]} не поддерживается классом.";
                elseif (isset($data[$attribute_name]) && !empty($data[$attribute_name])) {
                    if (!call_user_func(self::$valid_datatypes[$attribute[0]][0], $data[$attribute_name], ...array_slice(self::$valid_datatypes[$attribute[0]], 1)))
                        $errors[] = "Ожидаемый тип атрибута {$attribute_name} поля {$itemprop_name} не совпал с полученным. Ожидался '{$attribute[0]}'.";
                    else
                        $property[$attribute_name] = $data[$attribute_name];
                } elseif (!isset($attribute[1]) || $attribute[1])
                    $errors[] = "Не передан обязательный атрибут {$attribute_name} поля {$itemprop_name}."; 
            }

            if (!empty($errors))
                throw new \InvalidArgumentException(implode(PHP_EOL, $errors));

            return $property;
        };

        foreach (self::$vocabularies[$this->vocabulary]['types'][$this->type] as $itemprop_name => $itemprop) {

            if (isset($data[$itemprop_name])) {
                $local_errors = [];
                $is_multiple = isset($itemprop[2])
                    ? $itemprop[2]
                    : false;

                foreach ($itemprop[0] as $property_option) {
                    try {
                        $property = [$property_option, ...array_slice($itemprop, 1)];

                        $is_typeable = is_array($property[0]) &&
                            !in_array(false, array_map(function($element) {
                                return !is_array($element);
                            }, $property[0])) &&
                            $property[0][0][0] == '@';

                        $prototype[$itemprop_name] = (int)$is_typeable.'-'.(int)$is_multiple;

                        switch ($prototype[$itemprop_name]) {
                            case "1-1":
                                $obj_array = array_map(function ($element) use ($property_option, $itemprop_name) {
                                    $result = new self($this->vocabulary, $property_option[0], $element);
                                    $result->parent_property_name = $itemprop_name;

                                    return $result;
                                }, $data[$itemprop_name]);

                                if (
                                    !empty($obj_array) &&
                                    in_array(true, array_map(fn($element) => !empty($element->data), $obj_array), true)
                                ) {
                                    $new_data[$itemprop_name] = $obj_array;
                                    $new_data_array[$itemprop_name] = array_map(function ($element) {
                                        return $element->data;
                                    }, $obj_array);
                                } elseif (!isset($property_option[1]) || $property_option[1])
                                    throw new \InvalidArgumentException("Передан пустой массив данных микроразметки {$property_option[0]} для обязательного поля {$itemprop_name}.");

                                break;
                            case "1-0":

                                $obj = new self($this->vocabulary, $property_option[0], $data[$itemprop_name]);
                                $obj->parent_property_name = $itemprop_name;

                                if (!empty($obj->data)) {
                                    $new_data[$itemprop_name] = $obj;
                                    $new_data_array[$itemprop_name] = $obj->data;
                                } elseif (!isset($property_option[1]) || $property_option[1])
                                    throw new \InvalidArgumentException("Передан пустой массив данных микроразметки {$property_option[0]} для обязательного поля {$itemprop_name}.");

                                break;
                            case "0-1":
                                $new_data[$itemprop_name] = array_map(function ($m_itemprop) use ($property, $itemprop_name, $prepare_property) {
                                    return $prepare_property($m_itemprop, $property, $itemprop_name);
                                }, $data[$itemprop_name]);

                                $new_data_array[$itemprop_name] = $new_data[$itemprop_name];

                                break;
                            case "0-0":
                                $new_data[$itemprop_name] = $prepare_property($data[$itemprop_name], $property, $itemprop_name);

                                $new_data_array[$itemprop_name] = $new_data[$itemprop_name];

                                break;
                        }

                        $local_errors = null;
                        break;
                    } catch (\Exception $e) {
                        $local_errors[] = $e->getMessage();
                    }
                }

                if (!empty($local_errors))
                    $errors[] = implode(PHP_EOL, $local_errors);
                
            } elseif (isset($itemprop[1]) && $itemprop[1])
                $errors[] = "Не передано обязательное поле {$itemprop_name}.";
        }

        if (!empty($errors))
            throw new \InvalidArgumentException("Возникли ошибки при наполнения словаря {$this->vocabulary} микроразметки {$this->type}: ".PHP_EOL.implode(PHP_EOL, $errors));
        
        $this->data = $new_data;
        $this->data_array = $new_data_array;
        $this->prototype = $prototype;
    }

    public function append_data($data) {
        $this->set_data(array_merge(!empty($this->data_array) ? $this->data_array : [], $data));
    }

    public function reset_data($data) {
        $this->set_data(array_diff_key(!empty($this->data_array) ? $this->data_array : [], array_flip($data)));
    }

    public function json_ld() {

        if (empty($this->render_output['json_ld'])) {

            $json_ld_generator = function ($item, $level) use (&$json_ld_generator) {
                if ($level === 0)
                    $json_ld = '{"@context":"'.self::$vocabularies[$item->vocabulary]['context'].'","@type":"'.str_replace('@', '', $item->type).'"';
                else 
                    $json_ld = '{"@type":"'.str_replace('@', '', $item->type).'"';

                foreach ($item->data as $itemprop_name => $itemprop) {
                    switch ($item->prototype[$itemprop_name]) {
                        case "1-1":
                            $json_ld .= ',"'.$itemprop_name.'":['.implode(',',
                                array_map(function($element) use (&$json_ld_generator, $level) {
                                    return $json_ld_generator($element, $level+1);
                                }, $itemprop)
                            ).']';
                            break;
                        case "1-0":
                            $json_ld .= ',"'.$itemprop_name.'":'.$json_ld_generator($itemprop, $level+1);
                            break;
                        case "0-1":
                            $json_ld .= ',"'.$itemprop_name.'":['.implode(',',
                                array_map(function($element) {
                                    return '"'.reset($element).'"';
                                }, $itemprop)
                            ).']';
                            break;
                        case "0-0":
                            $json_ld .= ',"'.$itemprop_name.'":"'.reset($itemprop).'"';
                            break;
                    }
                }
        
                $json_ld .= '}';
        
                return $json_ld;
            };

            $this->render_output['json_ld'] = '<script type="application/ld+json">'.$json_ld_generator($this, 0).'</script>';
        }

        echo $this->render_output['json_ld'];
    }

    public function render($render_func = 0) {
        if (isset(self::$render_funcs_map[$this->vocabulary][$this->type][$render_func])) {

            if (empty($this->render_output['html'][$render_func])) {
                ob_start();
                    call_user_func(self::$render_funcs_map[$this->vocabulary][$this->type][$render_func], $this);
                $this->render_output['html'][$render_func] = ob_get_clean();
            }

            echo $this->render_output['html'][$render_func];
        }
    }

    /*
    ->data
    ->type
    ->context
    */
    public function __get($name) {
        switch ($name) {
            case 'data':
                return !empty($this->data_array) ? $this->data_array : [];
            case 'type':
                return $this->type;
            case 'context':
                return self::$vocabularies[$this->vocabulary]['context'];
            default:
                throw new \BadMethodCallException("Свойство '{$name}' не существует.");
        }
    }

    /*
    ->itemscope()
    ->vocab()
    ->property($itemprop_name)
    ->itemprop($itemprop_name)
    */
    public function __call($function_name, $arguments) {
        switch ($function_name) {
            case 'itemscope':
            case 'vocab':
                $rule = [
                    'itemscope' => [
                        'itemprop' => ' itemprop=',
                        'itemscope' => ' itemscope itemtype="',
                        'itemtype' => '/'
                    ],
                    'vocab' => [
                        'itemprop' => ' property=',
                        'itemscope' => ' vocab="',
                        'itemtype' => '/" typeof="'
                    ]
                ][$function_name];
                return ($this->parent_property_name ? $rule['itemprop'].$this->parent_property_name : '').$rule['itemscope'].self::$vocabularies[$this->vocabulary]['context'].$rule['itemtype'].str_replace('@', '', $this->type).'" ';
            
            case 'property':
            case 'itemprop':

                if (!isset(self::$vocabularies[$this->vocabulary]['types'][$this->type][$arguments[0]])) {
                    throw new \Exception("Свойство {$arguments[0]} не содержится в микроразметке {$this->type} словаря {$this->vocabulary}.");
                }
            
                if (!isset($this->data[$arguments[0]])) {
                    return null;
                }
            
                $prepare_property = function ($data, $name) use ($function_name) {
                    return new class($data, $name, $function_name) extends \stdClass {
                        private $data;
                        private $name;
                        private $itemprop_param_name;
            
                        public function __construct($data, $name, $itemprop_param_name) {
                            $this->data = $data;
                            $this->name = $name;
                            $this->itemprop_param_name = $itemprop_param_name;
                        }
            
                        public function __get($name) {
                            return $this->data[$name] ?? null;
                        }
            
                        public function is_markup() {
                            return false;
                        }

                        public function __toString() {
                            $itemprop = array_diff_key($this->data, ['value' => '']);
                            return implode(' ', array_merge(
                                array_map(fn($key, $value) => $key.'="'.$value.'"', array_keys($itemprop), $itemprop),
                                [$this->itemprop_param_name . '="'.$this->name.'"']
                            ));
                        }
                    };
                };
            
                switch ($this->prototype[$arguments[0]]) {
                    case "1-1":
                    case "1-0":
                        return $this->data[$arguments[0]];
                    case "0-1":
                        return array_map(fn($element) => $prepare_property($element, $arguments[0]), $this->data[$arguments[0]]);
                    case "0-0":
                        return $prepare_property($this->data[$arguments[0]], $arguments[0]);
                }

                return '';
            default:
                throw new \BadMethodCallException("Метод {$function_name} не существует.");
        }
    }
}

//example:

Markup::set_render_map([
    'schema.org' => [
        '@PostalAddress' => [
            function($markup) {?>
                <div <?=$markup->itemscope()?>>
                    <span <?=$markup->itemprop('streetAddress')?>><?=$markup->itemprop('streetAddress')->value?></span>
                    <span <?=$markup->itemprop('addressLocality')?>><?=$markup->itemprop('addressLocality')->value?></span>
                    <span <?=$markup->itemprop('addressRegion')?>><?=$markup->itemprop('addressRegion')->value?></span>
                    <span <?=$markup->itemprop('postalCode')?>><?=$markup->itemprop('postalCode')->value?></span>
                    <span <?=$markup->itemprop('addressCountry')?>><?=$markup->itemprop('addressCountry')->value?></span>
                </div>
            <?},
            'rdfa' => function($markup) {?>
                <div <?=$markup->vocab()?>>
                    <span <?=$markup->property('streetAddress')?>><?=$markup->property('streetAddress')->value?></span>
                    <span <?=$markup->property('addressLocality')?>><?=$markup->property('addressLocality')->value?></span>
                    <span <?=$markup->property('addressRegion')?>><?=$markup->property('addressRegion')->value?></span>
                    <span <?=$markup->property('postalCode')?>><?=$markup->property('postalCode')->value?></span>
                    <span <?=$markup->property('addressCountry')?>><?=$markup->property('addressCountry')->value?></span>
                </div>
            <?}
        ],
        '@Person' => [
            function ($markup) {?>
                <div <?=$markup->itemscope()?>>
                    <h1 <?=$markup->itemprop('name')?>><?=$markup->itemprop('name')->value?></h1>
                    <?if ($markup->itemprop('age')):?>
                        <p>Возраст: <span <?=$markup->itemprop('age')?>><?=$markup->itemprop('age')->value?></span></p>
                    <?endif;?>
                    <?if ($markup->itemprop('sameAs')):?>
                        <p>
                            Социальные сети:
                            <?foreach ($markup->itemprop('sameAs') as $property):?>
                                <span <?=$property?>><?=$property->value;?></span>
                            <?endforeach;?>
                        </p>
                    <?endif;?>
                    <p>Профессия: <span <?=$markup->itemprop('jobTitle')?>><?=$markup->itemprop('jobTitle')->value?></span></p>
                    <?if ($markup->itemprop('address')):?>
                        <p>Местоположение:
                            <?if ($markup->itemprop('address')->is_markup()):?>
                                <?$markup->itemprop('address')->render();?>
                            <?else:?>
                                <span <?=$markup->itemprop('address')?>><?=$markup->itemprop('address')->value?></span>
                            <?endif;?>
                        </p>
                    <?endif;?>
                    <p>Контакты: <span <?=$markup->itemprop('telephone')?>><?=$markup->itemprop('telephone')->href?></span></p>
                    <p>Электронная почта: <a <?=$markup->itemprop('email')?>><?=$markup->itemprop('email')->href?></a></p>
                    <img <?=$markup->itemprop('image')?>/>
                </div>
            <?},
            'rdfa' => function ($markup) {?>
                <div <?=$markup->vocab()?>>
                    <h1 <?=$markup->property('name')?>><?=$markup->property('name')->value?></h1>
                    <?if ($markup->property('age')):?>
                        <p>Возраст: <span <?=$markup->property('age')?>><?=$markup->property('age')->value?></span></p>
                    <?endif;?>
                    <?if ($markup->property('sameAs')):?>
                        <p>
                            Социальные сети:
                            <?foreach ($markup->property('sameAs') as $property):?>
                                <span <?=$property?>><?=$property->value;?></span>
                            <?endforeach;?>
                        </p>
                    <?endif;?>
                    <p>Профессия: <span <?=$markup->property('jobTitle')?>><?=$markup->property('jobTitle')->value?></span></p>
                    <?if ($markup->property('address')):?>
                        <p>Местоположение:
                            <?if ($markup->property('address')->is_markup()):?>
                                <?$markup->property('address')->render('rdfa');?>
                            <?else:?>
                                <span <?=$markup->property('address')?>><?=$markup->property('address')->value?></span>
                            <?endif;?>
                        </p>
                    <?endif;?>
                    <p>Контакты: <span <?=$markup->property('telephone')?>><?=$markup->property('telephone')->href?></span></p>
                    <p>Электронная почта: <a <?=$markup->property('email')?>><?=$markup->property('email')->href?></a></p>
                    <img <?=$markup->property('image')?>/>
                </div>
            <?},
        ],
    ]
]);

$PostalAddress = new Markup('schema.org', '@PostalAddress');

$PostalAddress->append_data([
    'postalCode' => ['25'],
    'addressLocality' => ['addressLocality'],
    'addressCountry' => ['addressCountry']
]);
$PostalAddress->reset_data(['addressCountry']);

$Person = new Markup('schema.org', '@Person', [
    'name' => ['Bob'],
    'jobTitle' => ['Test'],
    'image' => [
        'src' => 'https://raduzhka.com/local/templates/DNT_digital/assets/images/logo.svg',
        'alt' => 'add'
    ],
    'sameAs' => [
        ['http://schema-generator-project/'],
        ['http://schema-generator-project/']
    ],
    'email' => ['test@test.ru'],
    'address' => $PostalAddress->data
]);

$PostalAddress->json_ld();
$PostalAddress->render();
$PostalAddress->render('rdfa');

$Person->json_ld();
$Person->render();
$Person->render('rdfa');

echo Markup::help('schema.org', '@PostalAddress');