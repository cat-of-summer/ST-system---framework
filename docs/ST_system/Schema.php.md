# Schema.php

> Для AI-агентов: этот документ описывает **всю** публичную поверхность класса `Schema`.  
> Класс живёт в пространстве имён `ST_system`, файл `Schema.php`.

---

## 1. Концепция

`Schema` — **типизированный реестр сущностей (entities)** с валидацией, рендерингом и экспортом. Финальный класс.

Ключевые концепции:

1. **Entity** — именованный тип данных. Регистрируется через `Schema::entity()` один раз (повторная регистрация — исключение).
2. **Instance** — заполненный экземпляр. Создаётся через `Schema::create()->fill()`.
3. **Scope** — иерархический префикс для entity (`'schema.service'`).
4. **Поля entity.** Могут быть: Rule-строки, ссылки на entity (`@Name`), `Schema::arrayOf()`, `Schema::oneOf()`, `Closure` (вычисляемые поля).
5. **print / toArray.** Entity может иметь пользовательский `print`-callback (для XML/кастомного текста) и `toArray`-callback (для кастомной структуры). Если не заданы — дефолтные поведения.
6. **before/after-хуки.** Вызываются до/после `fill()` для всех экземпляров entity.

---

## 2. Статические методы

### `static entity(string $name, array $options = []): self`

Регистрирует entity в реестре. возвращает entity-инстанц, на котором можно вызвать `->before()`, `->after()`, `->scope()`.

| Ключ `$options` | Тип | Описание |
|----------------|-----|----------|
| `fields` | `array` | Схема полей (`'key' => spec`). Умолч: `[]` |
| `print` | `callable\|null` | `fn(Schema $s, array $params): string`. Умолч: `json_encode` |
| `toArray` | `callable\|null` | `fn(Schema $s, array $params): array`. Умолч: рекурсия `$this->data` |

**Бросает:** `RuntimeException` если entity с таким путём уже зарегистрирована.

```php
Schema::entity('Doctor', [
    'fields' => [
        'name'  => 'required|string',
        'email' => 'required|email',
        'age'   => 'sometimes|int|min:0',
    ],
    'print' => fn(Schema $s) => "<doctor>{$s->field('name')}</doctor>",
]);
```

---

### `static scope(string $fullPath, callable $fn): self`

Устанавливает назывной скоп для регистрации entity. Идемпотентен: добавляет префикс ковсем entity внутри `$fn`.

```php
Schema::scope('medical', function() {
    Schema::entity('Doctor', [...]);  // fullPath = 'medical.Doctor'
    Schema::entity('Clinic', [...]);  // fullPath = 'medical.Clinic'
});

$doc = Schema::create('medical.Doctor')->fill([...]);
```

---

### `static create(string $entityPath): self`

Создаёт пустой (unfilled) экземпляр entity.

**Бросает:** `RuntimeException` если entity не зарегистрирована.

---

### `static arrayOf(string $spec): object`

Создаёт маркер для поля-массива.

| Спецификация | Описание |
|-------------|----------|
| `'@Doctor'` | Массив entity 'Doctor' |
| `'string'` | Массив значений Rule 'string' |

```php
Schema::entity('Clinic', [
    'fields' => [
        'doctors' => Schema::arrayOf('@Doctor'),                   // обязательный
        'tags'    => [Schema::arrayOf('string'), 'sometimes'],     // необязательный
    ],
]);
```

---

### `static oneOf(array $specs): object`

Создаёт маркер «один из» нескольких вариантов.

```php
'author' => Schema::oneOf(['@Person', '@Organization'])
```

---

## 3. Инстанс-методы

### `fill(array $data, array $fillParams = []): self`

Заполняет экземпляр. Выполняет: before-хуки, валидацию (через `Rule::object()`), вычисляемые поля, after-хуки.

| Параметр | Описание |
|----------|----------|
| `$data` | Ассоциативный массив данных |
| `$fillParams` | Параметры контекста (пробрасываются в `print`/`toArray`) |

**Бросает:** `RuntimeException` со списком ошибок валидации.

```php
$doctor = Schema::create('Doctor')->fill([
    'name'  => 'Иванов',
    'email' => 'doc@med.ru',
], ['lang' => 'ru']);
```

---

### `append(array $data): self`

Повторно вызывает `fill(array_merge($rawData, $data), $fillParams)`.

```php
$doctor->append(['age' => 35]);
```

---

### `before(callable $fn): self`

Регистрирует before-хук на entity-определении. Вызывается до валидации. Callback: `fn(array &$data): void`.

```php
Schema::entity('Offer', [...])->before(function(array &$data): void {
    $data['price'] = (float)str_replace(',', '.', $data['price'] ?? '0');
});
```

---

### `after(callable $fn): self`

Регистрирует after-хук. Вызывается после `fill()`. Callback: `fn(Schema $s): void`. Для изменения данных — `$s->set()`.

---

### `set(string $path, mixed $value): self`

Напрямую записывает значение поля без валидации. Поддерживает dot-notation и `[N]`-индексы. Инвалидирует кэш печати.

```php
$doctor->set('name', 'Сидоров');
$doctor->set('items.0.price', 500);
$doctor->set('items.[1].name', 'Услуга 2');
```

---

### `print(array $params = []): string`

Возвращает строковое представление. `$params` мержится с `$fillParams`. Результат кэшируется если `$params` и `$fillParams` пусты.

```php
$xml = $doctor->print();            // с дефолтным json_encode если нет print-callback
$xml = $doctor->print(['format' => 'xml']); // параметр передаётся в callback
```

---

### `toArray(array $params = []): array`

Возвращает массив данных. Вложенные Schema-экземпляры разворачиваются рекурсивно. Без `toArray`-callback добавляет `'@type' => name`.

---

### `field(string $name = ''): mixed`

Читает поле или весь массив данных. Поддерживает dot-notation и `[N]`-индексы.

```php
$doctor->field();           // весь $this->data
$doctor->field('name');     // 'Иванов'
$doctor->field('items.0.price'); // вложенный доступ
```

---

### `parent(): ?self`

Возвращает родительский Schema-экземпляр или `null` если экземпляр корневой.

---

## 4. Спецификации полей entity

| Формат | Пример | Описание |
|--------|---------|----------|
| Rule-строка | `'required\|string\|max:100'` | Стандартная валидация |
| Ссылка на entity | `'@Address'` | Обязательный вложенный entity |
| `arrayOf` | `Schema::arrayOf('@Doctor')` | Массив entity |
| `oneOf` | `Schema::oneOf(['@A', '@B'])` | Один из вариантов |
| Closure | `fn(array $data): string` | Вычисляемое поле |
| Массив | `['@Doctor', 'sometimes']` | Маркер + модификаторы |
