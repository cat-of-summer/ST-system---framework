# Rule & Validator — Примеры использования

## Порядок применения правил (order)

| order | Правила |
|-------|---------|
| -1 | `before` (из массива-спека) |
| 0 | `sometimes`, `excludeIf` |
| 50 | `default` |
| 100 | `required`, `nullable`, `requiredIf`, `prohibitedIf` |
| 500 | `string`, `int`, `float`, `bool`, `email`, `url`, `array` |
| 700 | `max`, `min`, `in`, `notIn`, пользовательские правила |
| 1000 | `after` (из массива-спека), касты типов |

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

## 3. Rule::create с массивом (before / after / default)

```php
$rule = Rule::create([
    'default' => 'default_value',
    'rule'    => ['string', 'max:100'],
    'before'  => function(&$value): bool { $value = trim($value); return true; },
    'after'   => function(&$value): bool { $value = strtoupper($value); return true; },
]);

$v = '  hello  ';
$rule->apply($v);
// $v === 'HELLO'
```

Ключи массива:

| Ключ | Описание |
|------|----------|
| `rule` | строка `'required\|string'`, массив строк или callable |
| `default` | значение по умолчанию (если поле пустое/null) |
| `before` | callable-модификатор, выполняется первым (order -1) |
| `after` | callable-модификатор, выполняется последним (order 1000) |

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

Validator::create([
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

## 13. Validator::create + validate с мутацией

```php
$data = [
    'name'  => '  John  ',
    'age'   => '25',
    'extra' => 'will be removed',
];

Validator::create([
    'name' => Rule::create([
        'rule'   => 'required|string|max:100',
        'before' => function(&$value): bool { $value = trim($value); return true; },
    ]),
    'age' => 'required|int|min:0',
])->validate($data);

// $data['name']  === 'John' (обрезан trim)
// $data['age']   === 25    (int, прокастован)
// 'extra' удалено из $data — полей без правил нет
```

---

## 14. Validator::check (статический шорткат)

```php
$data = ['email' => 'user@test.com', 'name' => 'Alice'];

Validator::check([
    'email' => 'required|email',
    'name'  => 'nullable|string|max:255',
], $data);
```

---

## 15. silent mode (умолчание)

Исключения из `onError` перехватываются и сохраняются в `$validator->errors`.

```php
$data = ['test6' => 'not_a_number'];

$v = Validator::create([
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

$v = Validator::create([
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

$v = Validator::create([
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

Validator::create([
    'items' => ['required', 'array', Rule::forEach('string|required')],
    'tags'  => ['array', 'min:1', 'max:10'],
])->validate($data);
```

---

## 19. Приведение типов (int / float / bool)

```php
$data = ['count' => '42', 'price' => '9.99', 'active' => 'true'];

Validator::create([
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

Validator::create([
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
