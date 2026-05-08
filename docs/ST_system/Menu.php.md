# Menu.php

> Для AI-агентов: этот документ описывает **всю** публичную поверхность класса `Menu`.  
> Класс живёт в пространстве имён `ST_system`, файл `Menu.php`.

---

## 1. Концепция

`Menu` — **рендерер древовидного меню** в HTML. Работает с массивом элементов (вложенные `ITEMS`) и позволяет определить правила отрисовки для каждого уровня вложенности отдельно.

Структура массива меню:

```php
$menu = [
    'FIELDS'     => ['NAME' => 'Главное'],      // поля корневого узла
    'PROPERTIES' => [],                           // дополнительные свойства
    'ITEMS'      => [                             // дочерние элементы
        [
            'FIELDS'     => ['NAME' => 'О нас', 'LINK' => '/about', 'TYPE' => 'ITEM'],
            'PROPERTIES' => [],
            // 'ITEMS' => [...] — если есть вложенные элементы
        ],
        [
            'FIELDS'     => ['NAME' => 'Услуги', 'TYPE' => 'SECTION'],
            'PROPERTIES' => [],
            'ITEMS'      => [
                ['FIELDS' => ['NAME' => 'Услуга 1', 'LINK' => '/service1', 'TYPE' => 'ITEM'], 'PROPERTIES' => []],
            ],
        ],
    ],
];
```

**Когда использовать:**
- Для рендеринга древовидного HTML-меню (навигационного, мобильного, сайдбара).
- Когда нужные разные HTML-шаблоны для каждого уровня.

---

## 2. Публичные методы

### `__construct(mixed $PARAMS)`

Создаёт экземпляр `Menu`.

| Ключ `$PARAMS` | Тип | Описание |
|----------------|-----|----------|
| `menu` | `array\|string` | Массив меню или путь к файлу (тогда `require`) |
| `render_empty` | `bool` | `false` — не отрисовывать пустые секции |
| `render_rules` | `array` | Правила отрисовки по уровням |

**Бросает:** `Exception` если `menu` не передан, не найден файл, или значение не является массивом.

---

### `__call(string $name, array $arguments): string`

Обрабатывает только метод `render()`.

| Параметр | Описание |
|----------|----------|
| `$arguments[0]` | `array` правил отрисовки. При отсутствии — берётся из `$this->params['render_rules']`. |

**Возвращает:** `string` — отрендеренный HTML.

**Бросает:** `BadMethodCallException` если `$name !== 'render'`.

```php
$menu = new \ST_system\Menu([
    'menu' => $menuData,
    'render_empty' => false,
    'render_rules' => [
        // правила для уровня 0 — корневой
        0 => [
            'OPEN'  => '<nav><ul>',
            'ITEM'  => fn($FIELDS, $PROPS) => "<li><a href='{$FIELDS['LINK']}'>{$FIELDS['NAME']}</a></li>",
            'CLOSE' => '</ul></nav>',
        ],
        // правила для уровня 1 — вложенное
        1 => [
            'OPEN'  => fn($FIELDS, $PROPS) => "<ul class='sub'>",
            'ITEM'  => fn($FIELDS, $PROPS) => "<li>{$FIELDS['NAME']}</li>",
            'CLOSE' => '</ul>',
        ],
        // правила 'default' — для всех остальных уровней
        'default' => [
            'OPEN'  => '<ul>',
            'ITEM'  => fn($FIELDS) => "<li>{$FIELDS['NAME']}</li>",
            'CLOSE' => '</ul>',
        ],
    ],
]);

$html = $menu->render(); // использует правила из render_rules

// Или с передачей правил напрямую:
$html = $menu->render([
    0 => ['OPEN' => '<ul class="main">', 'ITEM' => fn($F) => "<li>{$F['NAME']}</li>", 'CLOSE' => '</ul>'],
]);
```

---

## 3. Формат правил отрисовки (`render_rules`)

Kаждый элемент массива `render_rules` идентифицируется по глубине вложенности (`0`, `1`, ...) или ключом `'default'`.

| Ключ в правиле | Тип | Описание |
|----------------|-----|----------|
| `OPEN` | `string\|Closure` | HTML-открывающий тег. Closure получает `($FIELDS, $PROPERTIES)`. |
| `ITEM` | `string\|Closure` | Шаблон элемента. Closure получает `($FIELDS, $PROPERTIES)`. |
| `CLOSE` | `string\|Closure` | HTML-закрывающий тег. Closure получает `($FIELDS, $PROPERTIES)`. |

Порядок выбора правила для глубины N: `render_rules[N]` → `render_rules['default']` → `render_rules[0]` → зашита.
