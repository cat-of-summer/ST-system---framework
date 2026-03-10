# Rule — Примеры использования

## Порядок применения правил (order)

| order | Правила |
|-------|---------|
| -1 | `trim`, `before()` |
| 0 | `sometimes`, `excludeIf` |
| 50 | `default` |
| 100 | `required`, `nullable`, `requiredIf`, `prohibitedIf` |
| 500 | `string`, `int`, `float`, `bool`, `email`, `url`, `array` |
| 700 | `max`, `min`, `in`, `notIn`, пользовательские правила |
| 1000 | `after()`, касты типов (`uppercase`, `lowercase`) |

---

## 1. Rule::create со строкой

```php
$rule = Rule::create('required|string|max:50');

$val = 'Hello World';
$rule->apply($val); // true

$val = '';
$rule->apply($val); // false — required не прошёл

$val = str_repeat('a', 60);
$rule->apply($val); // false — max:50
```

---

## 2. Rule::create с callable

```php
$rule = Rule::create(fn($v) => is_string($v) && strlen($v) > 5);

$rule->apply('Short');      // false
$rule->apply('Long enough'); // true
```

---

## 3. Fluent before / after

```php
$rule = Rule::create('required|string|max:100')
    ->before(function(&$value): bool { $value = trim($value); return true; })
    ->after(function(&$value): bool { $value = strtoupper($value); return true; });

$v = '  hello  ';
$rule->apply($v);
// $v === 'HELLO'
```

> `before()` — трансформ перед валидацией (order -1).
> `after()` — трансформ после валидации (order 1000).

---

## 4. Rule::object с массивом правил

```php
$rule = Rule::object([
    'field1' => 'required|string',
    'field2' => 'required|integer|min:0',
]);

$obj = ['field1' => 'test', 'field2' => '5'];
$rule->apply($obj);
// $obj['field2'] === 5 (int, прокастован)

$bad = ['field1' => '', 'field2' => -1];
$rule->apply($bad); // false
```

---

## 5. Rule::object с callable

```php
$rule = Rule::object(fn($obj) => isset($obj['field1']) && isset($obj['field2']));

$rule->apply(['field1' => 'a', 'field2' => 'b']); // true
$rule->apply(['field1' => 'a']);                   // false
```

---

## 6. Rule::forEach

```php
// Применить правило к каждому элементу массива
$rule = Rule::forEach('string|required');

$rule->apply(['hello', 'world', 'test']); // true
$rule->apply(['hello', '', 'test']);      // false

// С callable
$rule = Rule::forEach(fn($v) => strlen($v) <= 100);
$rule->apply(['short', 'ok']); // true
```

---

## 7. Rule::requiredIf

```php
$rule = Rule::requiredIf(true);
$v = '';
$rule->apply($v); // false

$rule = Rule::requiredIf(false);
$rule->apply($v); // true (условие не выполнено — правило не применяется)

// С callable
$role = 'admin';
$rule = Rule::requiredIf(fn() => $role === 'admin');
```

---

## 8. alias + Rule::get

```php
Rule::create('string|required')->alias('my_string');

$rule = Rule::get('my_string');
$v = 'test';
$rule->apply($v); // true
```

> Повторная регистрация того же алиаса для **другого** объекта бросит `RuntimeException`.

---

## 9. onError

```php
$rule = Rule::create('string|required')
    ->onError(fn($value) => "Value must be a non-empty string, got: " . var_export($value, true));

$v = 123;
$rule->apply($v);                  // false
$rule->getErrorMessage(123);       // "Value must be a non-empty string, got: 123"
```

---

## 10. onError с throw

```php
$rule = Rule::create('int|required')
    ->onError(function($value) {
        throw new \Exception("Error: expected int, got " . gettype($value));
    });

try {
    $v = 'not_int';
    $rule->apply($v);
} catch (\Exception $e) {
    echo $e->getMessage(); // "Error: expected int, got string"
}
```

---

## 11. Rule::in / Rule::notIn

```php
$rule = Rule::in(['apple', 'banana', 'cherry']);
$rule->apply('banana'); // true
$rule->apply('grape');  // false

$rule = Rule::notIn(['spam', 'trash']);
$rule->apply('good'); // true
```

---

## 12. sometimes + bool + default

```php
$data = ['param_1' => 'test@example.com', 'param_2' => 'hello'];

Rule::object([
    'param_1' => 'required|email',
    'param_2' => 'nullable|string|max:255',
    'param_3' => 'sometimes|bool|default:true',
])->validate($data);

// $data['param_1'] === 'test@example.com'
// $data['param_2'] === 'hello'
// $data['param_3'] — поле отсутствует (sometimes пропустил его)
```

> `sometimes` — пропустить поле целиком, если его нет в `$data`.
> `default:value` — подставить значение по умолчанию если поле пусто/null.

---

## 13. Rule::object + validate с мутацией

```php
$data = [
    'name'  => '  John  ',
    'age'   => '25',
    'extra' => 'will be removed',
];

Rule::object([
    'name' => Rule::create('required|string|max:100')
        ->before(function(&$value): bool { $value = trim($value); return true; }),
    'age' => 'required|int|min:0',
])->validate($data);

// $data['name']  === 'John' (обрезан trim через before)
// $data['age']   === 25    (int, прокастован)
// 'extra' удалено из $data — полей без правил нет
```

---

## 14. Rule::check (статический шорткат)

```php
$data = ['email' => 'user@test.com', 'name' => 'Alice'];

Rule::check([
    'email' => 'required|email',
    'name'  => 'nullable|string|max:255',
], $data);
```

---

## 15. silent mode (умолчание)

Исключения из `onError` перехватываются и сохраняются в `$v->errors`.

```php
$data = ['test6' => 'not_a_number'];

$v = Rule::object([
    'test6' => Rule::create('int|required')
        ->onError(function($value) { throw new \Exception("Error Processing Request"); }),
]);
$v->silent(); // режим по умолчанию
$v->validate($data);

// $v->errors === ['test6' => ['Error Processing Request']]
```

---

## 16. loud mode

Исключения из `onError` пробрасываются наружу.

```php
$data = ['val' => ''];

$v = Rule::object([
    'val' => Rule::create('required|string')
        ->onError(function($value) { throw new \Exception("val is required!"); }),
]);
$v->loud();

try {
    $v->validate($data);
} catch (\Exception $e) {
    echo $e->getMessage(); // "val is required!"
}
```

---

## 17. Вложенные ошибки (Rule::object + dot-нотация)

```php
$data = ['user' => ['name' => '', 'age' => 'abc']];

$v = Rule::object([
    'user' => Rule::object([
        'name' => 'required|string',
        'age'  => 'required|int',
    ]),
]);
$v->validate($data);

// $v->errors === [
//   'user.name' => ['Validation failed for name'],
//   'user.age'  => ['Validation failed for age'],
// ]
```

---

## 18. Массив правил (смешанный формат)

```php
$data = [
    'items' => ['hello', 'world'],
    'tags'  => ['php', 'validation'],
];

Rule::object([
    'items' => ['required', 'array', Rule::forEach('string|required')],
    'tags'  => ['array', 'min:1', 'max:10'],
])->validate($data);
```

---

## 19. Приведение типов (int / float / bool)

```php
$data = ['count' => '42', 'price' => '9.99', 'active' => 'true'];

Rule::object([
    'count'  => 'required|int',
    'price'  => 'required|float',
    'active' => 'required|bool',
])->validate($data);

// $data['count']  === 42    (int)
// $data['price']  === 9.99  (float)
// $data['active'] === true  (bool)
```

---

## 20. on_prepare callback

Вызывается после валидации — можно добавить вычисляемые поля.

```php
$data = ['first' => 'John', 'last' => 'Doe'];

Rule::object([
    'first' => 'required|string',
    'last'  => 'required|string',
])->validate($data, function(array &$data) {
    $data['full_name'] = $data['first'] . ' ' . $data['last'];
});

// $data['full_name'] === 'John Doe'
```

---

## Встроенные правила

| Правило | Описание |
|---------|----------|
| `sometimes` | Пропустить поле, если отсутствует в массиве |
| `default:val` | Подставить значение, если поле пусто или null |
| `required` | Поле обязательно (не null и не `''`) |
| `nullable` | Поле может быть null/`''`, последующие правила не применяются |
| `string` | Проверка `is_string()` |
| `int` / `integer` | Проверка + каст в `(int)` |
| `float` | Проверка + каст в `(float)` |
| `bool` | Проверка + каст в `(bool)` |
| `email` | `filter_var(FILTER_VALIDATE_EMAIL)` |
| `url` | `filter_var(FILTER_VALIDATE_URL)` |
| `array` | Проверка `is_array()` |
| `max:n` | Макс. длина строки / кол-во элементов / числовое значение |
| `min:n` | Мин. длина строки / кол-во элементов / числовое значение |
| `in:a,b,c` | Значение входит в список |
| `notIn:a,b,c` | Значение не входит в список |
| `regex:/pattern/` | Проверка регулярным выражением. Для паттернов с `\|` — `Rule::regex(...)` |
| `digits:n` | Строка из ровно `n` цифр |
| `between:min,max` | Значение/длина/кол-во в диапазоне [min, max] |
| `date` | Валидная дата (`strtotime`) |
| `date_format:Y-m-d` | Дата соответствует формату |
| `json` | Валидный JSON |
| `uuid` | UUID формат (v1–v5) |
| `starts_with:x,y` | Строка начинается с одного из значений |
| `ends_with:x,y` | Строка заканчивается одним из значений |
| `contains:x,y` | Строка содержит одно из значений |
| `same:field` | Значение совпадает с другим полем |
| `different:field` | Значение отличается от другого поля |
| `trim` | Трансформер: обрезает пробелы (order -1) |
| `uppercase` | Трансформер: приводит к верхнему регистру (order 1000) |
| `lowercase` | Трансформер: приводит к нижнему регистру (order 1000) |

---

## 21. passes() и fails()

```php
$data = ['email' => 'invalid'];

$v = Rule::object(['email' => 'required|email']);
$v->validate($data);

$v->passes(); // false
$v->fails();  // true
```

---

## 22. bail() — остановить после первой ошибки

```php
$data = ['a' => '', 'b' => '', 'c' => 'ok'];

$v = Rule::object([
    'a' => 'required|string',
    'b' => 'required|string',
    'c' => 'required|string',
])->bail();

$v->validate($data);
// $v->errors содержит только 'a' — обработка остановилась
```

---

## 23. regex, digits, between

```php
$data = ['code' => '1234', 'price' => 50, 'hex' => '#ff0000'];

Rule::object([
    'code'  => 'required|digits:4',
    'price' => 'required|int|between:1,1000',
    'hex'   => ['required', Rule::regex('/^#[0-9a-f]{6}$/i')],
])->validate($data);
```

> `Rule::regex(string)` — статический хелпер для паттернов, содержащих `|` (нельзя через строку `regex:/a|b/`).

---

## 24. date / date_format / json / uuid

```php
$data = [
    'created' => '2025-01-15',
    'payload' => '{"key": "value"}',
    'id'      => '550e8400-e29b-41d4-a716-446655440000',
];

Rule::object([
    'created' => 'required|date_format:Y-m-d',
    'payload' => 'required|json',
    'id'      => 'required|uuid',
])->validate($data);
```

---

## 25. starts_with / ends_with / contains

```php
$data = ['file' => 'image.png', 'url' => 'https://example.com'];

Rule::object([
    'file' => 'required|string|ends_with:.png,.jpg,.webp',
    'url'  => 'required|string|starts_with:https://,http://',
])->validate($data);
```

---

## 26. same / different (cross-field)

```php
$data = ['password' => 'secret', 'password_confirm' => 'secret'];

Rule::object([
    'password'         => 'required|string|min:6',
    'password_confirm' => 'required|same:password',
])->validate($data);
// OK — значения совпадают

$data2 = ['old' => 'abc', 'new' => 'xyz'];
Rule::object([
    'old' => 'required|string',
    'new' => 'required|string|different:old',
])->validate($data2);
// OK — значения различаются
```

---

## 27. trim / uppercase / lowercase (трансформеры)

```php
$data = ['name' => '  John  ', 'code' => 'abc'];

Rule::object([
    'name' => 'trim|required|string|max:100',
    'code' => 'trim|required|uppercase',
])->validate($data);

// $data['name'] === 'John'
// $data['code'] === 'ABC'
```

---

## 28. onError с ($value, $field)

```php
$rule = Rule::create('required|email')
    ->onError(fn($value, $field) => "Поле {$field}: ожидался email, получено " . var_export($value, true));

$data = ['email' => 'invalid'];
$v = Rule::object(['email' => $rule]);
$v->validate($data);

// $v->errors === ['email' => ['Поле email: ожидался email, получено \'invalid\'']]
```
