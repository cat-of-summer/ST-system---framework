# DefaultSchema.php

> Для AI-агентов: этот документ описывает **всю** публичную поверхность класса `DefaultSchema`.  
> Класс живёт в пространстве имён `ST_system\Schemas`, файл `ST_system/Schemas/DefaultSchema.php`.

---

## 1. Концепция

`DefaultSchema` — **базовый класс для типизированных схем** с валидацией полей, рендерингом и экспортом данных.

Два способа использования:

1. **Подкласс** — расширяешь `DefaultSchema`, переопределяешь `getFields()` и опционально `getPrint()`/`getToArray()`. Все конкретные схемы (`Doctor`, `Clinic`, `Service` и т.д.) используют этот подход.
2. **Инлайн-схема** — передаёшь конфиг в конструктор `new DefaultSchema([...])` без создания отдельного класса.

Ключевые концепции:

1. **Поля** — описываются в `getFields()`: Rule-строки, ссылки на другие схемы (`@ref`), маркеры `arrayOf`/`oneOf`, Closure (вычисляемые поля).
2. **`fill()`** — валидирует и заполняет экземпляр; обязателен перед `print()`/`toArray()`/`field()`.
3. **Хуки `before`/`after`** — вызываются до и после валидации.
4. **`print()`** — рендерит схему в строку (JSON / XML / JSON-LD). Если задан `getPrint()` — использует его, иначе `json_encode`.
5. **`toArray()`** — экспортирует в массив. Если задан `getToArray()` — использует его.
6. **Вложенные схемы** — поля-ссылки `@ref` автоматически создают и заполняют дочерние экземпляры.

---

## 2. Методы для подклассов (protected, переопределяются)

```php
protected static function getFields(): array  // правила валидации полей
protected static function getPrint(): ?\Closure  // кастомный рендер → string
protected static function getToArray(): ?\Closure  // кастомный экспорт → array
protected static function _init(): void  // хук инициализации класса (один раз)
```

**Сигнатуры коллбэков:**

- `getPrint()` возвращает `fn(DefaultSchema $s, array $params): string`
- `getToArray()` возвращает `fn(DefaultSchema $s, array $params): array`

```php
namespace ST_system\Schemas;

class Doctor extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'name'  => 'required|string',
            'email' => 'required|email',
            'age'   => 'sometimes|int|min:0',
        ];
    }

    protected static function getPrint(): ?\Closure
    {
        return fn(self $s, array $p) => "<doctor>{$s->field('name')}</doctor>";
    }
}
```

---

## 3. Статические методы (final)

### `static create(...$args): self`

Фабричный метод — эквивалент `new static(...)`. Удобен для цепочек.

```php
$doc = Doctor::create()->fill(['name' => 'Иванов', 'email' => 'doc@med.ru']);
```

---

### `static name(): string`

Возвращает **kebab-case** имя класса (по `basename`).

```php
Doctor::name();      // 'doctor'
OfferCatalog::name(); // 'offer-catalog'
```

---

### `static scope(): string`

Возвращает dot-путь неймспейса относительно `ST_system\Schemas`, **без имени самого класса**.

```php
// ST_system\Schemas\SchemaOrg\Service\Offer
Offer::scope(); // 'schema-org.service'
```

---

### `static path(): string`

Возвращает `scope() + '.' + name()`. Если scope пустой — только `name()`.

```php
Offer::path(); // 'schema-org.service.offer'
Doctor::path(); // 'doctor' (если в корне ST_system\Schemas)
```

---

### `static arrayOf(string $spec): object`

Создаёт маркер для поля-массива схем или скалярных Rule-значений.

| Спецификация | Описание |
|-------------|----------|
| `'@doctor'` | Массив вложенных схем `Doctor` |
| `'string'` | Массив Rule-строк |
| `Doctor::class` | Массив конкретного класса (FQCN) |

```php
protected static function getFields(): array
{
    return [
        'doctors' => static::arrayOf('@doctor'),            // обязательный
        'tags'    => [static::arrayOf('string'), 'sometimes'], // необязательный
    ];
}
```

---

### `static oneOf(array $specs): object`

Создаёт маркер «один из» — значение должно пройти хотя бы одну из спецификаций.

```php
'author' => static::oneOf(['@person', '@organization'])
```

---

## 4. Инстанс-методы (final)

### `fill(array $data, array $fillParams = []): self`

Валидирует и заполняет экземпляр данными. Выполняет: before-хуки → валидация полей → вычисляемые поля → after-хуки.

| Параметр | Тип | Описание |
|----------|-----|----------|
| `$data` | `array` | Ассоциативный массив входных данных |
| `$fillParams` | `array` | Параметры контекста (пробрасываются в `print`/`toArray`) |

**Бросает:** `RuntimeException` со списком ошибок если валидация не прошла.

```php
$doc = (new Doctor())->fill([
    'name'  => 'Иванов',
    'email' => 'doc@med.ru',
    'age'   => 35,
]);
```

---

### `append(array $data): self`

Мержит `$data` поверх исходных данных и повторно вызывает `fill()`. Если экземпляр ещё не заполнен — просто вызывает `fill($data)`.

```php
$doc->append(['age' => 40]);
```

---

### `before(callable $fn): self`

Регистрирует хук **до** валидации. Вызывается при каждом `fill()`. Callback: `fn(array &$data): void`.

```php
(new Doctor())
    ->before(function (array &$data): void {
        $data['name'] = trim($data['name'] ?? '');
    })
    ->fill(['name' => ' Иванов ', 'email' => 'doc@med.ru']);
```

---

### `after(callable $fn): self`

Регистрирует хук **после** `fill()`. Callback: `fn(DefaultSchema $s): void`. Для изменения данных используй `$s->set()`.

```php
(new Doctor())
    ->after(function (DefaultSchema $s): void {
        if ($s->field('age') < 18) {
            $s->set('age', 18);
        }
    })
    ->fill(['name' => 'Иванов', 'email' => 'doc@med.ru', 'age' => 5]);
```

---

### `set(string $path, mixed $value): self`

Напрямую записывает значение по dot-notation пути **без валидации**. Поддерживает `[N]`-индексы. Инвалидирует кэш `print`.

```php
$doc->set('name', 'Сидоров');
$doc->set('address.city', 'Москва');
$doc->set('items.0.price', 500);
$doc->set('items.[1].name', 'Услуга 2');  // [N] и N эквивалентны
```

---

### `field(string $name = ''): mixed`

Читает поле по dot-notation пути. Пустая строка возвращает весь массив данных. Поддерживает `[N]`-индексы.

```php
$doc->field();                  // ['name' => 'Иванов', 'email' => '...']
$doc->field('name');            // 'Иванов'
$doc->field('address');         // DefaultSchema-инстанс address
$doc->field('address.city');    // делегирует → $address->field('city')
$doc->field('items.0');         // $data['items'][0]
$doc->field('items.0.price');   // $data['items'][0]->field('price')
```

---

### `print(array $params = []): string`

Возвращает строковое представление схемы. Если определён `getPrint()` — использует его; иначе `json_encode(toArray())`.

`$params` мержится с `$fillParams`. Результат кэшируется если оба пусты.

```php
echo $doc->print();                    // JSON или кастомный вывод
echo $doc->print(['format' => 'xml']); // параметр пробрасывается в getPrint()
```

**Бросает:** `RuntimeException` если экземпляр не заполнен.

---

### `toArray(array $params = []): array`

Экспортирует данные в массив. Вложенные `DefaultSchema`-экземпляры рекурсивно разворачиваются. Если определён `getToArray()` — использует его.

```php
$arr = $doc->toArray(); // ['name' => 'Иванов', 'email' => '...']
```

---

### `parent(): ?self`

Возвращает родительский экземпляр `DefaultSchema` если эта схема является вложенным полем. Иначе `null`.

```php
$address = $clinic->field('address');
$address->parent() === $clinic; // true
```

---

## 5. Инлайн-схема

Альтернатива созданию подкласса — передать конфиг в конструктор `DefaultSchema`:

```php
$schema = new DefaultSchema([
    'fields' => [
        'title'   => 'required|string',
        'content' => 'sometimes|string',
    ],
    'print'   => fn($s, $p) => "<article><h1>{$s->field('title')}</h1></article>",
    'toArray' => fn($s, $p) => ['headline' => $s->field('title')],
]);

$result = $schema->fill(['title' => 'Новость'])->print();
// <article><h1>Новость</h1></article>
```

| Ключ `$inline` | Тип | Описание |
|----------------|-----|----------|
| `fields` | `array` | Правила полей (те же форматы что в `getFields()`) |
| `print` | `Closure\|null` | `fn(DefaultSchema $s, array $params): string` |
| `toArray` | `Closure\|null` | `fn(DefaultSchema $s, array $params): array` |

---

## 6. Спецификации полей (`getFields()`)

| Формат | Пример | Описание |
|--------|---------|----------|
| Rule-строка | `'required\|string\|max:100'` | Стандартная Rule-валидация |
| `@ref` | `'@address'` | Обязательная вложенная схема (по имени) |
| FQCN | `Address::class` | Обязательная схема по полному имени класса |
| `arrayOf` | `static::arrayOf('@doctor')` | Обязательный массив схем/значений |
| `oneOf` | `static::oneOf(['@a', '@b'])` | Один из вариантов |
| Closure | `fn(array $d): string` | Вычисляемое поле (не валидируется, исполняется после валидации) |
| Массив | `[static::arrayOf('@doctor'), 'sometimes']` | Маркер + модификаторы Rule |
| Строка с `@` + модификаторы | `'@address\|sometimes'` | Необязательная вложенная схема |
