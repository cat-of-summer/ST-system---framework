<?php

namespace ST_system;

class Menu {
    private static $DEFAULT = [
        'OPEN' => '<ul>',
        'ITEM' => null,
        'CLOSE' => '</ul>'
    ];

    public $menu = [];
    private $params = [
        'render_empty' => false,
        'render_rules' => []
    ];

    public function __construct($PARAMS) {
        if (!isset($PARAMS['menu']))
            throw new \Exception("Не передано меню!");
             
        if (is_string($PARAMS['menu'])) {
            if (!file_exists($PARAMS['menu']))
                throw new \Exception("Передана путь к файлу с меню, но файл не найден!");

            $PARAMS['menu'] = require $PARAMS['menu'];
        }

        if (!is_array($PARAMS['menu']))
            throw new \Exception('Меню не оказалось массивом!');

        $this->menu = $PARAMS['menu'];

        foreach ($this->params as $key => &$value) 
            if (isset($PARAMS[$key]))
                $value = $PARAMS[$key];
    }

    private function render(array $render_rules, array $ITEMS, array $FIELDS, array $PROPERTIES, int $depth) {
        $render_params = !empty($render_rules[$depth])
            ? $render_rules[$depth]
            : (!empty($render_rules['default'])
                ? $render_rules['default']
                : (!empty($render_rules[0])
                    ? $render_rules[0]
                    : [
                        'OPEN' => self::$DEFAULT['OPEN'],
                        'ITEM' => fn($FIELDS = [], $PROPERTIES = []) => "<li>{$FIELDS['NAME']}</li>",
                        'CLOSE' => self::$DEFAULT['CLOSE']
                    ]
                )
            );

        $OPEN = is_callable($render_params['OPEN']) ? $render_params['OPEN']($FIELDS, $PROPERTIES) : $render_params['OPEN'];
        $CLOSE = is_callable($render_params['CLOSE']) ? $render_params['CLOSE']($FIELDS, $PROPERTIES) : $render_params['CLOSE'];

        $html = !empty($OPEN) ? $OPEN : self::$DEFAULT['OPEN'];

        foreach ($ITEMS as $ITEM)
            if (isset($ITEM['ITEMS']))
                $html .= $this->render($render_rules, $ITEM['ITEMS'], $ITEM['FIELDS'], $ITEM['PROPERTIES'], $depth + 1);
            elseif (
                (bool)$this->params['render_empty'] ||
                $ITEM['FIELDS']['TYPE'] != 'SECTION' ||
                $ITEM['FIELDS']['TYPE'] == 'ITEM'
            )
                $html .= is_callable($render_params['ITEM']) ? $render_params['ITEM']($ITEM['FIELDS'], $ITEM['PROPERTIES']) : $render_params['ITEM'];
        
        $html .= !empty($CLOSE) ? $CLOSE : self::$DEFAULT['CLOSE'];

        return $html;
    }

    /*
    render()
    */
    public function __call($name, $arguments) {
        switch ($name) {
            case 'render':
                if (empty($arguments[0]))
                    $arguments[0] = (array)$this->params['render_rules'];

                return $this->render($arguments[0], $this->menu['ITEMS'], $this->menu['FIELDS'], $this->menu['PROPERTIES'], 0);
                break;
            default:
                throw new \BadMethodCallException("Метод {$name}() не существует.");
        }
    }
}

/*
$Menu = new \ST_system\Menu([
    'menu' => [
        'ITEMS' => [],
        'FIELDS' => [],
        'PROPERTIES' => []
    ],
    'render_empty' => true,
    'render_rules' => [
        [
            'OPEN' => fn() => '<div>',
            'ITEM' => function($FIELDS, $PROPERTIES) use ($arParams) {
                return 
                <<<HTML
                    <a href="{$FIELDS['SECTION_PAGE_URL']}">
                        <p>{$FIELDS['NAME']}</p>
                    </a>
                HTML;
            },
            'CLOSE' => '</div>',
        ],
    ]
]);

$Menu->render();
*/