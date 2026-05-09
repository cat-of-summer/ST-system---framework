# Rule.php

> Для AI-агентов: этот документ описывает **всю** публичную поверхность класса `Rule`.  
> Класс живёт в пространстве имён `ST_system`, файл `Rule.php`.  
> Используй примеры кода как шаблоны при генерации кода для этого проекта.

---

## 1. Концепция

`Rule` — это **иммутабельно-fluent движок валидации и трансформации** данных.

Ключевые идеи:

1. **Правило = объект Rule.** Каждое правило содержит callback-функцию, которая принимает значение **по ссылке** (`&$v`) — это позволяет правилу **мутировать** значение (каст типа, trim и т.д.) прямо во время проверки.

2. **Результат — массив строк ошибок.** Пустой массив `[]` означает успех. Ненулевой — список ошибок. Нет исключений при нормальной работе.

3. **Порядок (`order`).** Когда несколько правил применяются к одному значению, они сортируются по `order` (от меньшего к большему). Это позволяет, например, `trim` (order=-2) отработать до `required` (order=100).

4. **Skip (`skip=true`).** Если правило с `skip=true` не прошло — цепочка прерывается. Это позволяет `required` остановить все дальнейшие правила, если поле пусто.

5. **Трансформеры vs валидаторы.** Правило без `handleError` работает как чистый трансформер: оно может мутировать `$v`, но никогда не генерирует ошибку. Правило с `handleError` — валидатор: если callback вернул `false`/`0`/непустой массив, `handleError` вызывается для получения строки ошибки.

6. **Реестр + алиасы.** Правила можно зарегистрировать в глобальном реестре по строковому имени через `->alias('name')`. После этого их можно использовать в строковых спецификациях `'required|string|max:50'`. После вызова `alias()` объект **замораживается** — его нельзя менять.

7. **Sentinel "undefined" прозрачен для пользователя.** Внутри `Rule::object()` отсутствующее поле представляется специальным sentinel-объектом, который позволяет отличить `['key' => null]` от `[]` (ключ отсутствует). Встроенные правила (`sometimes`, `required`, `nullable` и др.) работают с sentinel напрямую. **Для пользовательских замыканий** (`callback`, `before`, `after`, `handleError`) отсутствующее поле всегда представлено как `null` — без `stdClass`. Используй `sometimes`/`nullable`/`default` для управления поведением отсутствующих полей.

---

## 2. Жизненный цикл значения

При применении правила к значению происходит следующее:

```
1. before(&$v)           ← мутация до callback (опционально)
2. callback(&$v, $params) ← основная логика: проверка и/или мутация
   ├── вернул true/1          → passed, нет ошибок
   ├── вернул false/0         → failed, вызвать handleError если есть
   ├── вернул []              → passed
   └── вернул ['err1', ...]   → failed, вызвать handleError если есть (заменяет список)
3. after(&$v)            ← мутация после callback (опционально)
```

Если `callback` бросил исключение — возвращается `[false, [$e->getMessage()]]`, `after` не вызывается.

---

## 3. Порядок встроенных правил

| order | Правила |
|-------|---------|
| -3 | `default` |
| -2 | `trim`, `ltrim`, `rtrim`, `escape_html`, `uppercase`, `lowercase` |
| 0 | `sometimes`, `excludeIf` |
| 100 | `required`, `nullable`, `present`, `requiredIf`, `prohibitedIf` |
| 500 | `string`, `int`/`integer`, `float`, `bool`, `email`, `url`, `array`, `foreach`, `date`, `date_format`, `json`, `uuid`, `accepted`, `declined`, `callable`, `closure`, `file` |
| 600 | `mimes`, `extension`, `filesize` |
| 700 | `max`, `min`, `in`, `notIn`/`not_in`, `regex`, `digits`, `between`, `starts_with`, `ends_with`, `contains` |

> Все трансформеры на `-2` — выполняются **до всех валидаторов**, чтобы проверки шли уже по нормализованному значению. `default` на `-3` — раньше трансформеров, чтобы подставленное значение тоже нормализовалось (например, `'default:  HELLO  |trim|lowercase'` корректно даст `'hello'`).

---

## 4. Публичные методы

### 4.1 `Rule::create($spec): Rule` — фабрика

Создаёт правило из трёх форматов:

**Closure** — произвольная логика:

```php
$rule = Rule::create(function(&$v): bool {
    return is_string($v) && strlen($v) > 3;
});
// callback принимает &$v (по ссылке) — можно мутировать
// callback принимает второй аргумент array $params (параметры из строки 'name:p1,p2')
```

**Строка** — pipe-separated список алиасов из реестра:

```php
$rule = Rule::create('required|string|max:50');
// Разворачивается в цепочку правил, отсортированных по order
// Параметры передаются через двоеточие: 'max:50', 'between:1,100', 'in:a,b,c'
```

**Массив** — смешанный формат строк и Rule-объектов:

```php
$rule = Rule::create([
    'required',
    'array',
    Rule::forEach('int'),
]);
```

---

### 4.2 `->apply(&$data): string[]` — применить правило

Основная точка входа. **Мутирует `$data`** (trim, cast и т.д.). Возвращает массив ошибок.

```php
$v = '  42  ';
$errors = Rule::create('trim|required|int')->apply($v);
// $errors === []
// $v === 42  (int, обрезан и прокастован)

$v = '';
$errors = Rule::create('required|string')->apply($v);
// $errors === ['This field is required']
// $v === ''  (не мутировано после ошибки required+skip)
```

---

### 4.3 `->check($data): string[]` — проверить без мутации вызывающего кода

Принимает значение **по значению**. Удобно для литералов. Внутри всё равно происходит мутация копии.

```php
$errors = Rule::create('required|int')->check('abc');
// ['Must be an integer']

$errors = Rule::create('required|int')->check('42');
// []  — строка '42' является валидным int (будет прокастована во внутренней копии)
```

---

### 4.4 `->before(Closure|mixed): self` — хук до callback

Выполняется **до** основного callback. Мутирует `$v`.  
Если передать не-Closure — хук обнуляется.

```php
$rule = Rule::create(fn(&$v) => is_string($v))
    ->before(fn(&$v) => $v = trim($v))
    ->handleError(fn($v) => 'must_be_string');

$v = '  hello  ';
$rule->apply($v);
// $v === 'hello'  — trim отработал в before
```

---

### 4.5 `->after(Closure|mixed): self` — хук после callback

Выполняется **после** основного callback, даже при неуспехе.  
Если передать не-Closure — хук обнуляется.

```php
$rule = Rule::create(fn(&$v) => strlen($v) <= 100)
    ->after(fn(&$v) => $v = strtoupper($v))
    ->handleError(fn($v) => 'too_long');

$v = 'hello';
$rule->apply($v);
// $v === 'HELLO'
```

---

### 4.6.1 `->handleError(Closure|mixed): self` — обработчик ошибки

Вызывается когда callback вернул `false`/`0` или непустой массив.  
Должна вернуть **строку** — это и будет сообщение об ошибке.  
Если возвращает не-строку — ошибка не засчитывается (правило работает как трансформер).  
Если не задан — правило никогда не генерирует ошибку (pure transformer).

```php
// Валидатор
$rule = Rule::create(fn(&$v) => $v > 0)
    ->handleError(fn($v) => "Value {$v} must be positive");

// Трансформер (без handleError — нет ошибок)
$rule = Rule::create(fn(&$v) => $v = mb_strtolower($v));

// Динамическое сообщение
$rule = Rule::create(fn(&$v) => strlen($v) <= 10)
    ->handleError(fn($v) => 'Max 10 chars, got ' . strlen($v));
```

Если callback вернул **массив строк** и задан `handleError` — `handleError` получает этот массив вторым аргументом и может его заменить:

```php
$rule = Rule::create(fn(&$v) => ['error_a', 'error_b'])
    ->handleError(fn($v, array $errors) => 'Overridden: ' . implode(', ', $errors));
// Ошибки заменяются одной строкой: ['Overridden: error_a, error_b']
```

---

### 4.6.2 `->throwable(): self` — обработчик ошибки

Обёртка над `->handleError(Closure|mixed): self`.
Переопределяется обработчик ошибки, как замыкание, вызывающее `throw \Exception`.
Необходимо, если достаточно вызывать исключение в качестве обработки ошибок.
Передаёт построчно ошибки, которые были допущены при валидации.

---

### 4.7 `->order(int): self` — приоритет в цепочке

Меньшее значение — раньше выполняется. Используется для управления порядком в pipes.

```php
$rule = Rule::create(fn(&$v) => $v = trim($v))->order(-1); // раньше всех
```

---

### 4.8 `->skip(bool = true): self` — прервать цепочку при неуспехе

Если правило с `skip=true` вернуло `false` — остальные правила в цепочке не выполняются.

```php
// required с skip=true — если пусто, max/string не проверяются
$rule = Rule::create('required|string|max:50');
$v = '';
$rule->apply($v); // ['This field is required']  — max не запустился
```

---

### 4.9 `->alias(string): self` — зарегистрировать в реестре

После вызова правило доступно по имени в строковых спецификациях. Объект **замораживается** (нельзя менять `order`, `skip`, `before` и т.д.).  
Повторная регистрация другого объекта под тем же именем → `RuntimeException`.

```php
Rule::create(fn(&$v) => filter_var($v, FILTER_VALIDATE_IP) !== false)
    ->handleError(fn($v) => 'Invalid IP address')
    ->alias('ip');

// Теперь можно использовать в строках:
Rule::create('required|ip');
Rule::object(['remote_ip' => 'required|ip']);
```

---

### 4.10 `Rule::get(string): ?Rule` — получить из реестра

```php
$rule = Rule::get('required'); // встроенное правило
$rule = Rule::get('ip');       // пользовательское
$rule = Rule::get('noname');   // null если не найдено
```

---

### 4.11 `Rule::object(array $schema): Rule` — валидация ассоциативного массива

Принимает схему полей. Применяет правила к каждому полю. **Мутирует** `$data`:
- поля, прошедшие проверку, остаются в `$data`
- поля, не объявленные в схеме, **удаляются** из `$data`
- поля, которые после `sometimes`/`excludeIf` стали "undefined", **не попадают** в результат
- ошибки prefixed именем поля: `'field.Error message'`
- работает с массивами и объектами (объект кастуется в массив)

**Обычный синтаксис:**

```php
$rule = Rule::object([
    'name'  => 'required|string|max:100',
    'email' => 'required|email',
    'age'   => 'required|int|min:0|max:150',
    'bio'   => 'nullable|string|max:500',
]);

$data = ['name' => 'Alice', 'email' => 'alice@example.com', 'age' => '28', 'extra' => 'removed'];
$errors = $rule->apply($data);
// $errors === []
// $data === ['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 28]
// 'extra' удалён, 'bio' не попал (undefined → не добавляется)
```

**Значение схемы** может быть:
- строкой `'required|string'`
- объектом Rule
- массивом строк/Rule: `['required', 'array', Rule::forEach('int')]`

**Dot-notation ключи** (автоматически разворачиваются во вложенные `object`/`forEach`):

```php
$rule = Rule::object([
    'id'                => 'required|int',         // обычный ключ
    'meta.title'        => 'required|string',       // → object(['meta' => object(['title' => ...])])
    'meta.active'       => 'required|bool',
    'tags.*'            => 'required|string',       // → object(['tags' => forEach('required|string')])
    'users.*.name'      => 'required|string',       // → forEach(object(['name' => ..., 'age' => ...]))
    'users.*.age'       => 'required|int',
]);
```

> Нельзя одновременно задать ключ `'data'` как обычный и `'data.field'` через dot-notation — будет `RuntimeException`.  
> Нельзя смешивать `*` с именованными ключами на одном уровне пути (`'items.*'` + `'items.count'`) — будет `RuntimeException`.

**Вложенные Rule::object напрямую:**

```php
$rule = Rule::object([
    'address' => Rule::object([
        'city'    => 'required|string',
        'zip'     => 'required|digits:5',
        'country' => 'required|string|max:2',
    ]),
]);

$data = ['address' => ['city' => 'NYC', 'zip' => '10001', 'country' => 'US']];
$errors = $rule->apply($data);
// Ошибки имеют формат: 'address.city.Error message'
```

---

### 4.12 `Rule::forEach($spec): Rule` — валидация каждого элемента массива

Применяет правило к каждому элементу итерируемого значения. Ошибки prefixed индексом: `'0.Error'`, `'1.Error'`.

```php
// Строка-спецификация
$rule = Rule::forEach('required|int');
$data = ['1', '2', 'abc'];
$errors = $rule->apply($data);
// ['2.Must be an integer']
// $data === [1, 2, 'abc']  — первые два прокастованы

// Rule-объект
$rule = Rule::forEach(Rule::object([
    'name' => 'required|string',
    'age'  => 'required|int',
]));

// Closure
$rule = Rule::forEach(fn(&$v) => $v = strtoupper($v));
```

---

### 4.13 `Rule::requiredIf($cond): Rule` — обязательное если условие

`$cond` — `bool` или `Closure(): bool`. Если true — поле обязательно (не `null`, не `''`, не undefined).

> **Эквивалентность:** `Rule::requiredIf(true)` полностью идентичен строковому алиасу `'required'` —
> та же логика, тот же `order=100`, тот же `skip=true`. Используй `requiredIf(true)` когда нужно
> смешать с Rule-объектами в массиве:
> ```php
> // Эти две записи идентичны:
> 'name' => 'required|string|max:100'
> 'name' => [Rule::requiredIf(true), 'string|max:100']
> ```

```php
$isCompany = true;

$rule = Rule::object([
    'company_name' => [Rule::requiredIf($isCompany), 'string|max:200'],
]);

// С замыканием — вычисляется в момент валидации:
$role = 'admin';
$rule = Rule::requiredIf(fn() => $role === 'admin');
```

---

### 4.14 `Rule::prohibitedIf($cond): Rule` — запрещено если условие

Если условие `true` — поле должно быть пустым (`null`, `''`) или отсутствовать. При нарушении генерирует **ошибку**, значение при этом остаётся в `$data` как есть.

```php
$rule = Rule::object([
    'internal_comment' => [Rule::prohibitedIf(!$isAdmin), 'string'],
]);

// При !$isAdmin === true и пришедшем непустом значении:
// $errors === ['internal_comment.This field is not allowed']
// $data['internal_comment'] — значение остаётся (клиент получает и ошибки, и данные)
```

> **Сравнение с `excludeIf`:**
>
> | | `prohibitedIf` | `excludeIf` |
> |---|---|---|
> | условие `true`, поле непустое | **ошибка**, значение остаётся | тихое удаление, ошибок нет |
> | назначение | валидация — «клиент прислал запрещённое поле» | фильтрация — «убрать поле из результата» |
>
> Используй `prohibitedIf` когда нужно сообщить клиенту об ошибке.
> Используй `excludeIf` когда нужно молча отфильтровать поле независимо от его значения.

---

### 4.15 `Rule::excludeIf($cond): Rule` — исключить поле если условие

Если условие `true` — поле **удаляется** из результирующего `$data`. Последующие правила не выполняются.

> Внутри `Rule::object()` поле после `excludeIf` устанавливается в sentinel `UNDEFINED`.
> После обхода всех правил поля условие выполняется:
> ```php
> if ($temp !== $UNDEFINED) {
>     $result[$key] = $temp;  // поле попадает в результат
> }
> // иначе — ключ просто не добавляется, как будто его никогда не было
> ```
> Это позволяет отличить `['key' => null]` (null попадёт в результат) от `[]` (ключ исчезает).

```php
$rule = Rule::object([
    'debug_info' => [Rule::excludeIf(!$isDevMode), 'string'],
]);

$data = ['id' => 1, 'debug_info' => 'trace...'];
$rule->apply($data);
// При $isDevMode=false:
// $errors === []
// $data === ['id' => 1]  ← 'debug_info' удалён, ключа нет совсем

// При $isDevMode=true — поле проходит правила ('string') как обычно
```

---

### 4.16 `Rule::when($cond, $spec): Rule` — применить правила если условие

Если `$cond` истинно — применяет `$spec` (строка или Rule). Иначе пропускает.

```php
$isStrict = true;

$rule = Rule::object([
    'password' => [
        'required|string',
        Rule::when($isStrict, 'min:12'),
        Rule::when(fn() => $isStrict, Rule::regex('/[A-Z]/')),
    ],
]);
```

---

### 4.17 `Rule::in(array): Rule` — значение из набора (статический помощник)

Используется когда набор содержит `|` или формируется динамически — вместо строки `'in:a,b,c'`.

```php
$allowed = ['active', 'inactive', 'pending|review']; // содержит | — нельзя в строке
$rule = Rule::in($allowed);

$v = 'active';
$rule->apply($v); // []

$v = 'deleted';
$rule->apply($v); // ['Not a valid option']
```

---

### 4.18 `Rule::notIn(array): Rule` — значение не из набора (статический помощник)

```php
$rule = Rule::object([
    'username' => ['required', 'string', Rule::notIn(['admin', 'root', 'system'])],
]);
```

---

### 4.19 `Rule::regex(string): Rule` — проверка паттерном (статический помощник)

Для паттернов, содержащих `|` — их нельзя передать через строковую спецификацию `'regex:/a|b/'` (парсер разобьёт по `|`).

```php
$rule = Rule::regex('/^#[0-9a-f]{6}$/i');   // hex-цвет
$rule = Rule::regex('/^\+?[0-9\s\-()]{7,15}$/'); // телефон

$v = '#ff0000';
Rule::regex('/^#[0-9a-f]{6}$/i')->apply($v); // []
```

---

## 5. Встроенные правила

### 5.1 Правила наличия/обязательности

#### `sometimes` (order=0, skip=true)

Если поле **отсутствует** в данных — пропускает все последующие правила **без ошибки**.  
Используется для полностью опциональных полей в `Rule::object`.

```php
$rule = Rule::object([
    'nickname' => 'sometimes|string|max:50',
]);

$data = [];
$rule->apply($data); // [] — поле отсутствует, пропущено

$data = ['nickname' => 'alice'];
$rule->apply($data); // [] — проверено и ОК

$data = ['nickname' => 123];
$rule->apply($data); // ['nickname.Must be a string'] — поле есть, но невалидно
```

#### `required` (order=100, skip=true)

Поле обязательно: не `null`, не `''`, не отсутствует.  
`skip=true` — при провале дальнейшие правила не запускаются.

```php
$rule = Rule::create('required|int|min:1');

Rule::create('required')->apply(''); // ['This field is required']
Rule::create('required')->apply(null); // ['This field is required']
Rule::create('required')->apply(0); // []  — 0 считается присутствующим
```

#### `nullable` (order=100, skip=true)

Если значение `null`, `''` или отсутствует — пропускает остальные правила **без ошибки**.  
Поле не обязательно, но если есть — должно пройти остальные проверки.

```php
$rule = Rule::object([
    'bio' => 'nullable|string|max:500',
]);

$data = ['bio' => null];
$rule->apply($data); // [] — null допустим

$data = ['bio' => 42];
$rule->apply($data); // ['bio.Must be a string']

$data = ['bio' => 'Hello'];
$rule->apply($data); // []
```

#### `present` (order=100, skip=true)

Поле **должно существовать** в данных, но может быть пустым (`null`, `''` — допустимо).

```php
$rule = Rule::object(['field' => 'present']);

(['field' => null]) → []          // поле есть (null — ОК)
(['field' => ''])   → []          // поле есть (пустота — ОК)
([])                → ['field.This field must be present']
```

---

### 5.2 Трансформеры

#### `trim` (order=-2)

Убирает пробелы по краям строки. Выполняется раньше всех остальных правил.

```php
$v = '  hello  ';
Rule::create('trim|required|string')->apply($v);
// $v === 'hello'
```

#### `rtrim` (order=-2)

Убирает пробелы с правого края строки. Выполняется раньше всех остальных правил.

```php
$v = '  hello  ';
Rule::create('rtrim|required|string')->apply($v);
// $v === '  hello'
```

#### `ltrim` (order=-2)

Убирает пробелы с левого края строки. Выполняется раньше всех остальных правил.

```php
$v = '  hello  ';
Rule::create('ltrim|required|string')->apply($v);
// $v === 'hello  '
```

#### `escape_html` (order=-2)

Применяет htmlspecialchars на строку. Выполняется раньше всех остальных правил.

```php
$v = '  hello  ';
Rule::create('escape_html|required|string')->apply($v);
// $v === 'hello'
```

#### `default:value,valid` (order=-3)

Подставляет значение если поле `null`, `''` или отсутствует.

```php
$rule = Rule::object([
    'status'  => 'default:active|string',
    'page'    => 'default:1|int',
    'perPage' => 'default:20|int',
]);

$data = [];
$rule->apply($data);
// $data === ['status' => 'active', 'page' => 1, 'perPage' => 20]
```

Логика параметра valid

  Если valid == true, то если $value == default, значение пропускается, иначе идут последующие проверки

#### `uppercase` / `lowercase` (order=-2)

Преобразует строку в верхний/нижний регистр. Выполняется после всех валидаций.

```php
$v = 'hello';
Rule::create('required|uppercase')->apply($v); // $v === 'HELLO'

$v = 'WORLD';
Rule::create('required|lowercase')->apply($v); // $v === 'world'
```

---

### 5.3 Правила типов (с автокастом)

#### `string`

Проверяет `is_string`. Не кастует — если не строка, возвращает ошибку.

#### `callable`

Проверяет `is_callable`. Не кастует — если не callable, возвращает ошибку.

#### `closure`

Проверяет `instanceof \Closure`. Не кастует — если не instanceof \Closure, возвращает ошибку.

#### `int` / `integer`

Принимает `int` или строку из цифр (с возможным минусом). Кастует строку в `int`.

```php
$v = '42';  Rule::create('int')->apply($v); // $v === 42 (int)
$v = '-7';  Rule::create('int')->apply($v); // $v === -7 (int)
$v = '3.14'; Rule::create('int')->apply($v); // ['Must be an integer']
$v = 'abc';  Rule::create('int')->apply($v); // ['Must be an integer']
```

#### `float`

Принимает `float` или любое числовое значение. Кастует в `float`.

```php
$v = '9.99'; Rule::create('float')->apply($v); // $v === 9.99 (float)
$v = '42';   Rule::create('float')->apply($v); // $v === 42.0 (float)
```

#### `bool`

Принимает `bool` или строки/числа: `'0'`, `'1'`, `0`, `1`, `'true'`, `'false'`. Кастует.

```php
$v = 'true';  Rule::create('bool')->apply($v); // $v === true
$v = '0';     Rule::create('bool')->apply($v); // $v === false
$v = 'yes';   Rule::create('bool')->apply($v); // ['Must be a boolean']
```

#### `array`

Проверяет `is_array`. Не кастует.

#### `foreach:rule1,rule2,...`

Проверяет, что если значение — массив, то применяет правила к каждому элементу. Ошибки prefixed индексом: `'0.Error'`, `'1.Error'`.

Параметры через запятую объединяются в pipe-спеку: `'foreach:required,string,max:50'` → `Rule::forEach('required|string|max:50')`.  
Ограничение: параметры с запятыми внутри (`in:a,b,c`) ломают парсинг — для таких случаев использовать `Rule::forEach(...)` напрямую.

```php
// Базовое использование
$v = ['1', '2', 'abc'];
$errors = Rule::create('foreach:int')->apply($v);
// $errors === ['2.Must be an integer']
// $v === [1, 2, 'abc']  — первые два прокастованы

// Без параметров — только проверка типа
$errors = Rule::create('foreach')->check([]);
// $errors === []

// Несколько правил
$errors = Rule::create('foreach:required,string,max:50')->check(['alice', '', 'bob']);
// $errors === ['1.This field is required']

// В схеме объекта
$rule = Rule::object([
    'tags' => 'required|foreach:string|min:1',
]);
```

> Для сложных спек используй `Rule::forEach(...)` напрямую:
> ```php
> Rule::object([
>     'items' => ['required', 'array', Rule::forEach(Rule::object([
>         'id'   => 'required|int',
>         'name' => 'required|string',
>     ]))],
> ]);
> ```

#### `email`

Проверяет через `filter_var($v, FILTER_VALIDATE_EMAIL)`.

#### `url`

Проверяет через `filter_var($v, FILTER_VALIDATE_URL)`.

#### `date`

Проверяет через `strtotime($v)`. Принимает любой парсируемый формат.

```php
Rule::create('date')->check('2025-01-15'); // []
Rule::create('date')->check('January 15'); // []
Rule::create('date')->check('not-a-date'); // ['Invalid date']
```

#### `date_format:FORMAT`

Строгая проверка формата через `DateTime::createFromFormat`.

```php
Rule::create('date_format:Y-m-d')->check('2025-01-15'); // []
Rule::create('date_format:Y-m-d')->check('15-01-2025'); // ['Invalid date format']
Rule::create('date_format:d/m/Y')->check('15/01/2025'); // []
```

#### `json`

Проверяет что строка является валидным JSON.

```php
Rule::create('json')->check('{"key":"value"}'); // []
Rule::create('json')->check('[1,2,3]');          // []
Rule::create('json')->check('{bad}');            // ['Invalid JSON']
```

#### `uuid`

Проверяет формат UUID v4 (`xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx`).

```php
Rule::create('uuid')->check('550e8400-e29b-41d4-a716-446655440000'); // []
Rule::create('uuid')->check('not-uuid'); // ['Invalid UUID']
```

#### `accepted` / `declined`

`accepted` — значение должно быть одним из: `true`, `1`, `'1'`, `'yes'`, `'on'`, `'true'`.  
`declined` — значение должно быть одним из: `false`, `0`, `'0'`, `'no'`, `'off'`, `'false'`.

```php
Rule::create('accepted')->check('yes'); // []
Rule::create('accepted')->check('no');  // ['Must be accepted']

Rule::create('declined')->check('off'); // []
Rule::create('declined')->check('on');  // ['Must be declined']
```

---

### 5.4 Правила диапазонов/длин

#### `min:n` / `max:n`

Работают по-разному в зависимости от типа значения:
- **строка**: проверяют `mb_strlen` (количество символов)
- **число**: сравнивают числовое значение
- **массив**: проверяют `count`

```php
// Строки
Rule::create('string|min:3')->check('ab');     // ['Value is too small']
Rule::create('string|max:5')->check('hello');  // []
Rule::create('string|max:5')->check('toolong') // ['Value is too large']

// Числа (нужен тип int/float до max/min, т.к. order)
Rule::create('int|min:1|max:100')->check('50'); // []
Rule::create('int|min:1|max:100')->check('0');  // ['Value is too small']

// Массивы
Rule::create('array|min:1')->check([]);      // ['Value is too small']
Rule::create('array|max:3')->check([1,2,3]); // []
```

#### `between:min,max`

Аналогично `min`+`max`, но короче. Работает для строк, чисел и массивов.

```php
Rule::create('int|between:1,100')->check('50');  // []
Rule::create('string|between:3,10')->check('hi'); // ['Value is out of range']
```

#### `digits:n`

Строка должна состоять **ровно из `n` цифр** (только `[0-9]`, без знака и точки).

```php
Rule::create('digits:4')->check('1234'); // []
Rule::create('digits:4')->check('123');  // ['Must be digits only']
Rule::create('digits:4')->check('12ab'); // ['Must be digits only']
```

---

### 5.5 Правила нахождения в наборе

#### `in:val1,val2,...`

Использовать через строку если значение не содержит `|`. Иначе — `Rule::in([...])`.

```php
Rule::create('in:active,inactive,pending')->check('active');  // []
Rule::create('in:active,inactive,pending')->check('deleted'); // ['Not a valid option']
```

#### `notIn:val1,val2,...` / алиас `not_in`

```php
Rule::create('notIn:admin,root')->check('user');  // []
Rule::create('notIn:admin,root')->check('admin'); // ['Value is not allowed']
```

---

### 5.6 Правила формата строк

#### `regex:pattern`

Проверяет строку по регулярному выражению. **Ограничение**: паттерн не должен содержать `|` (иначе парсер строки сломает его). Для сложных паттернов используй `Rule::regex(...)`.

```php
Rule::create('regex:/^\d{4}-\d{2}-\d{2}$/')->check('2025-01-15'); // []

// Паттерн с | — только через статический метод:
Rule::regex('/^(foo|bar)$/')->check('foo'); // []
```

#### `starts_with:prefix1,prefix2,...`

Строка должна начинаться с одного из указанных префиксов.

```php
Rule::create('starts_with:https://,http://')->check('https://example.com'); // []
Rule::create('starts_with:https://,http://')->check('ftp://example.com');   // ['Invalid prefix']
```

#### `ends_with:suffix1,suffix2,...`

Строка должна заканчиваться на один из суффиксов.

```php
Rule::create('ends_with:.png,.jpg,.webp')->check('photo.jpg');  // []
Rule::create('ends_with:.png,.jpg,.webp')->check('photo.gif');  // ['Invalid suffix']
```

#### `contains:substr1,substr2,...`

Строка должна содержать хотя бы одну из подстрок.

```php
Rule::create('contains:@')->check('user@example.com');        // []
Rule::create('contains:hello,world')->check('say hello!');    // []
Rule::create('contains:hello,world')->check('nothing here');  // ['Must contain']
```

---

### 5.7 Правила файлов (для $_FILES)

Правила работают с элементом массива `$_FILES['field']`.

#### `file`

Проверяет что значение — корректно загруженный файл: есть ключи `tmp_name`, `error`, `size`, `name`; `error === UPLOAD_ERR_OK`; `is_uploaded_file(tmp_name)`.

#### `mimes:type1,type2,...`

Проверяет MIME-тип через `finfo`. Параметры — полные MIME-строки.

```php
$rule = Rule::object([
    'avatar' => ['file', 'mimes:image/jpeg,image/png,image/webp', 'filesize:2048'],
]);
```

#### `extension:ext1,ext2,...`

Проверяет расширение имени файла (регистронезависимо).

```php
$rule = Rule::object([
    'document' => ['file', 'extension:pdf,doc,docx'],
]);
```

#### `filesize:N`

N — максимальный размер **в килобайтах**.

```php
$rule = Rule::object([
    'photo' => ['file', 'mimes:image/jpeg', 'filesize:5120'], // макс. 5 MB
]);
```

---

## 6. Алиасы (реестр правил)

### Регистрация пользовательского правила

```php
// Один алиас
Rule::create(fn(&$v) => filter_var($v, FILTER_VALIDATE_IP) !== false)
    ->handleError(fn($v) => 'Invalid IP address')
    ->order(500)
    ->alias('ip');

// Несколько алиасов на один объект (цепочка)
Rule::create(fn(&$v) => is_int($v) && $v > 0)
    ->handleError(fn($v) => 'Must be positive integer')
    ->order(500)
    ->alias('positive_int')
    ->alias('pos_int');

// Использование:
Rule::create('required|ip');
Rule::create('required|positive_int|max:999');
```

### Rule::object как алиас

```php
Rule::object([
    'name'  => 'required|string|max:100',
    'email' => 'required|email',
    'age'   => 'required|int|min:0',
])->alias('user');

// Переиспользование:
$data = ['name' => 'Alice', 'email' => 'a@b.com', 'age' => 25];
Rule::create('user')->apply($data);  // работает через реестр

// Массив пользователей:
$rule = Rule::forEach('user');
```

### Заморозка

После `->alias()` объект **заморожен**: вызов `->order()`, `->skip()`, `->before()` и т.д. выбросит `RuntimeException`.

```php
$rule = Rule::get('required');
$rule->order(999); // RuntimeException: Cannot modify a frozen Rule
```

---

## 7. Dot-notation в Rule::object

При передаче ключей с точкой в схему `Rule::object` они **автоматически** разворачиваются в вложенную структуру. Это эквивалентно ручному написанию вложенных `Rule::object`/`Rule::forEach`.

### Правила dot-notation

| Паттерн ключа | Результат |
|---|---|
| `'a.b'` | `Rule::object(['a' => Rule::object(['b' => ...])])` |
| `'a.b.c'` | три уровня вложенных `object` |
| `'a.*'` | `Rule::object(['a' => Rule::forEach(...)])` |
| `'a.*.name'` | `Rule::object(['a' => Rule::forEach(Rule::object(['name' => ...]))])` |
| `'a.*'` + `'a.*.name'` | **нельзя** — `RuntimeException` (смешение leaf и subtree под `*`) |
| `'a.*'` + `'a.b'` | **нельзя** — `RuntimeException` (смешение `*` с именованными) |
| `'data'` + `'data.b'` | **нельзя** — `RuntimeException` (конфликт regular и dot-ключа) |

### Примеры dot-notation

**Простая вложенность:**

```php
$rule = Rule::object([
    'user.name'  => 'required|string',
    'user.email' => 'required|email',
    'user.age'   => 'required|int',
]);

$data = ['user' => ['name' => 'Bob', 'email' => 'bob@x.com', 'age' => '30']];
$errors = $rule->apply($data);
// $errors === []
// $data['user']['age'] === 30 (прокастован)
// Ошибки имели бы вид: 'user.name.This field is required'
```

**forEach через `*`:**

```php
$rule = Rule::object([
    'tags.*' => 'required|string|max:30',
]);
$data = ['tags' => ['php', 'laravel', 'vue']];
$rule->apply($data); // []
// Ошибки вида: 'tags.0.Must be a string', 'tags.1.Value is too large', ...
```

**Сложная вложенность с forEach:**

```php
$rule = Rule::object([
    'order.items.*.name'     => 'required|string',
    'order.items.*.qty'      => 'required|int|min:1',
    'order.items.*.price'    => 'required|float|min:0',
    'order.shipping.address' => 'required|string',
    'order.shipping.city'    => 'required|string',
]);

$data = [
    'order' => [
        'items' => [
            ['name' => 'Widget', 'qty' => '2', 'price' => '9.99'],
            ['name' => 'Gadget', 'qty' => '1', 'price' => '49.00'],
        ],
        'shipping' => ['address' => '123 Main St', 'city' => 'NYC'],
    ],
];

$errors = $rule->apply($data);
// $errors === []
// $data['order']['items'][0]['qty'] === 2 (int)
// Ошибки вида: 'order.items.0.name.This field is required'
```

**Смешанная схема (regular + dot):**

```php
$rule = Rule::object([
    'id'           => 'required|int',          // обычный ключ
    'meta.title'   => 'required|string|max:200', // dot-notation
    'meta.slug'    => 'required|string',
    'tags.*'       => 'required|string',        // forEach
]);

$data = [
    'id'   => '42',
    'meta' => ['title' => 'Article', 'slug' => 'article'],
    'tags' => ['php', 'news'],
];
$errors = $rule->apply($data);
// $errors === []
// $data['id'] === 42 (int)
```

---

## 8. Пользовательские правила-трансформеры

Правило **без `handleError`** никогда не генерирует ошибок — оно только мутирует значение.

```php
// Нормализация телефона: убрать всё кроме цифр и +
Rule::create(function(&$v): bool {
    if (is_string($v)) {
        $v = preg_replace('/[^\d+]/', '', $v);
    }
    return true;
})->order(-1)->alias('normalize_phone');

// Slug-ификация
Rule::create(function(&$v): bool {
    if (is_string($v)) {
        $v = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $v), '-'));
    }
    return true;
})->order(1000)->alias('slugify');

// Использование:
$rule = Rule::object([
    'phone' => 'required|normalize_phone|string|min:10',
    'title' => 'required|string|slugify',
]);
```

---

## 9. Условные правила

### Rule::requiredIf + Rule::when вместе

```php
$type = 'company';

$rule = Rule::object([
    'type'         => 'required|in:personal,company',
    'company_name' => [Rule::requiredIf(fn() => $type === 'company'), 'string|max:200'],
    'vat_number'   => [
        Rule::requiredIf(fn() => $type === 'company'),
        Rule::when(fn() => $type === 'company', 'string|digits:9'),
    ],
    'first_name'   => [Rule::requiredIf(fn() => $type === 'personal'), 'string'],
]);
```

### Rule::excludeIf для условного удаления

```php
$isPublic = false;

$rule = Rule::object([
    'title'      => 'required|string',
    'secret_key' => [Rule::excludeIf($isPublic), 'required|string'],
    // При $isPublic=true поле secret_key будет удалено из результата
]);
```

---

## 10. Обработка ошибок и формат

### Формат ошибок

- Одиночное проверяемое значение: `['Error message', ...]`
- Внутри `Rule::object`: `['field.Error message', 'nested.field.Error message', ...]`
- Внутри `Rule::forEach`: `['0.Error message', '1.Error message', ...]`
- Совмещение: `['users.0.name.This field is required', 'users.1.email.Invalid email address']`

### Получение ошибок по полю

```php
$errors = $rule->apply($data);

// Фильтрация по префиксу
$nameErrors = array_filter($errors, fn($e) => str_starts_with($e, 'name.'));

// Группировка
$byField = [];
foreach ($errors as $err) {
    $dot = strpos($err, '.');
    $field = $dot !== false ? substr($err, 0, $dot) : $err;
    $byField[$field][] = $err;
}
```

### Исключения (не ошибки валидации)

`RuntimeException` бросается только при **конфигурационных ошибках**:
- Неизвестный алиас в строке `'required|unknown_rule'`
- Попытка изменить замороженное правило
- Повторная регистрация алиаса другим объектом
- Конфликт ключей в dot-notation схеме

---

## 11. Полный сценарий — форма регистрации

```php
// Регистрируем кастомное правило (один раз, при старте приложения)
Rule::create(fn(&$v) => is_string($v) && preg_match('/^[a-z][a-z0-9_]{2,19}$/i', $v) === 1)
    ->handleError(fn($v) => 'Username must be 3-20 chars: letters, digits, underscore')
    ->order(700)
    ->alias('username');

// Схема формы
$rule = Rule::object([
    'username'              => 'trim|required|string|username',
    'email'                 => 'trim|required|email',
    'password'              => 'required|string|min:8',
    'password_confirmation' => 'required|string',
    'age'                   => 'nullable|int|min:18|max:120',
    'country'               => 'trim|required|string|in:US,CA,GB,DE,FR',
    'tags.*'                => 'required|string|max:20',
    'profile.bio'           => 'nullable|string|max:500',
    'profile.website'       => 'nullable|url',
]);

$data = [
    'username'              => '  alice  ',
    'email'                 => '  alice@example.com  ',
    'password'              => 'secret123',
    'password_confirmation' => 'secret123',
    'age'                   => '25',
    'country'               => 'US',
    'tags'                  => ['php', 'music'],
    'profile'               => ['bio' => 'Developer', 'website' => 'https://alice.dev'],
    'extra_field'           => 'will be removed',
];

$errors = $rule->apply($data);

// $errors === []
// $data['username'] === 'alice'       — trim + кастомное правило
// $data['email']    === 'alice@example.com'  — trim
// $data['age']      === 25            — int cast
// $data['tags']     === ['php', 'music']
// $data['profile']  === ['bio' => 'Developer', 'website' => 'https://alice.dev']
// 'extra_field' — удалено из $data
```

---

## 12. Тонкости и edge-cases

### `null` vs отсутствие поля

```php
$rule = Rule::object(['field' => 'required|string']);

// Поле отсутствует → sentinel "undefined" → required срабатывает
(['other' => 1]) → ['field.This field is required']

// Поле есть, но null → required срабатывает
(['field' => null]) → ['field.This field is required']

// Поле есть, пустая строка → required срабатывает
(['field' => '']) → ['field.This field is required']

// 0 и false — НЕ пустые для required
(['field' => 0])     → []
(['field' => false]) → []
```

### `sometimes` vs `nullable`

```php
// sometimes — если поле отсутствует, пропускает ВСЕ правила (без ошибки)
//           — если поле есть, проверяет дальше
'sometimes|string' → если поле есть, оно должно быть строкой

// nullable — если поле null/''/отсутствует, пропускает ВСЕ правила (без ошибки)
//          — если поле есть и не пустое, проверяет дальше
'nullable|string' → если поле есть и непустое, оно должно быть строкой
```

### Трансформеры в цепочке с валидаторами

```php
// Все трансформеры (order=-2) выполняются ДО всех валидаторов,
// независимо от порядка написания:
Rule::create('required|trim|string') // то же что 'trim|required|string'
// После trim — если строка была '   ', она станет '' и required упадёт

// uppercase/lowercase (order=-2) — также до валидаций
Rule::create('lowercase|in:foo,bar')
// 'FOO' → 'foo' → проходит in:foo,bar

// default (order=-3) выполняется раньше трансформеров,
// чтобы подставленное значение тоже нормализовалось:
Rule::create('default:  HELLO  |trim|lowercase')
// При пустом входе: подставится '  HELLO  ' → trim → 'HELLO' → lowercase → 'hello'
```

### Правило как часть массива-спецификации

```php
// Смешанный синтаксис — строки и Rule-объекты в одном массиве
$rule = Rule::object([
    'hex_color' => [
        'required',
        'string',
        Rule::regex('/^#[0-9a-f]{6}$/i'),  // объект-правило
    ],
    'items' => [
        'required',
        'array',
        Rule::forEach(Rule::object([         // forEach с вложенным object
            'id'    => 'required|int',
            'label' => 'required|string|max:50',
        ])),
    ],
]);
```

### Нормализация структурированных конфигурационных массивов

Практический пример — нормализация правил URL-роутинга (`MODULE_URL_RULES` в `BX_facade\ST_Module`).

Поля `RULE`, `ID`, `SORT` опциональны и имеют дефолтные значения. `CONDITION` и `PATH` обязательны.

```php
$schema = Rule::object([
    'CONDITION' => 'required|string',   // обязательное
    'PATH'      => 'required|string',   // обязательное
    'RULE'      => 'default:',          // → '' если не задано
    'ID'        => 'default',           // → null если не задано (нет параметра → $p[0] ?? null = null)
    'SORT'      => 'default:100|int',   // → 100 (int) если не задано
]);

// Минимальное правило — только обязательные поля:
$rule = ['CONDITION' => '#^/api/#', 'PATH' => '/local/modules/my/api/index.php'];
$errors = $schema->apply($rule);
// $errors === []
// $rule === ['CONDITION' => '#^/api/#', 'PATH' => '...', 'RULE' => '', 'ID' => null, 'SORT' => 100]

// Полное правило — все поля переданы явно, дефолты не срабатывают:
$rule = ['CONDITION' => '#^/api/#', 'PATH' => '...', 'RULE' => '', 'ID' => null, 'SORT' => 1];
$errors = $schema->apply($rule);
// $errors === []
// $rule['SORT'] === 1  — явное значение сохранено

// Ошибка — отсутствует обязательное поле:
$rule = ['PATH' => '/local/modules/my/api/index.php'];
$errors = $schema->apply($rule);
// $errors === ['CONDITION.This field is required']
```

> **Почему `'default:'` → `''`, а `'default'` → `null`:**
> Правило `default` работает как `$v = $p[0] ?? null`. При `default:` параметр `$p[0] = ''`.
> При `default` (без двоеточия) массив параметров пуст — `$p[0] ?? null = null`.

---

### before / after на Rule::object

```php
// before/after применяются к ВСЕМУ объекту, не к полям
$rule = Rule::object(['name' => 'required|string'])
    ->before(fn(&$v) => $v = is_string($v) ? json_decode($v, true) : $v)
    ->after(fn(&$v) => $v['_validated'] = true);

$data = '{"name":"Alice"}'; // JSON-строка → before декодирует → object валидирует → after маркирует
$rule->apply($data);
// $data === ['name' => 'Alice', '_validated' => true]
```
