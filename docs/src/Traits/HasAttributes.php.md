# HasAttributes.php

## 1. Концепция

`HasAttributes` — **трейт динамического доступа к атрибутам** через магические методы `__get` / `__set` / `__isset` / `__unset`.

Ключевые идеи:

1. **Защищённый массив `$attributes`.** Всё состояние объекта хранится в одном массиве `protected array $attributes = []`. Тип и ключи определяются конкретным классом.

2. **Аксессоры и мутаторы (accessor / mutator pattern).** Если в классе определён метод `getFooAttribute()` — он будет вызван при обращении к `$obj->foo`. Аналогично `setFooAttribute($value)` — при записи. Это позволяет добавлять ленивые вычисления, каст типов и защиту без изменения публичного API.

3. **Карта атрибутов `attributeMap()`.** Способ объявить атрибут одной строкой, не заводя отдельный метод-аксессор. Ключ — имя атрибута, значение — `[callable, bool $cache = false]`. Позволяет выставить наружу существующий метод как свойство: `$file->relative_path` вместо `$file->getRelativePath()`.

4. **Мемоизация чистых атрибутов.** Второй элемент определения — флаг кэширования. `true` означает, что значение вычисляется один раз за жизнь объекта и складывается в `$attribute_cache`. Для volatile-значений (размер файла, mtime) флаг оставляют `false`.

5. **Fallback на массив.** Если ни аксессор, ни карта не подошли — значение берётся напрямую из `$this->attributes[$name]`, или возвращается `null`.

**Когда использовать:**
- В классах-сущностях (ORM-подобные модели, DTO), где нужна чистая инкапсуляция.
- Когда нужна возможность перехватить чтение/запись конкретных полей через методы.
- Когда у класса уже есть набор `get*()`-методов без аргументов и хочется дать к ним доступ как к свойствам.

```php
// Пример класса использующего трейт
class User {
    use \ST_system\Traits\HasAttributes;

    // Мутатор — автоматически хэширует пароль при записи
    protected function setPasswordAttribute(string $value): void {
        $this->attributes['password'] = password_hash($value, PASSWORD_BCRYPT);
    }

    // Акссесор — возвращает имя в uppercase
    protected function getNameAttribute(): string {
        return strtoupper($this->attributes['name'] ?? '');
    }

    // Карта — выставляет методы наружу как свойства
    protected function attributeMap(): array {
        return [
            'slug'      => ['buildSlug', true],   // считается один раз
            'age'       => ['calcAge'],           // пересчитывается каждый раз
            'timestamp' => [fn() => time()],      // произвольное замыкание
        ];
    }

    public function buildSlug(): string { /* … */ }
    public function calcAge(): int      { /* … */ }
}

$user = new User();
$user->name = 'ivan';       // напрямую в $attributes['name']
$user->password = 'qwerty'; // вызовет setPasswordAttribute()

echo $user->name;           // вызовет getNameAttribute() → 'IVAN'
echo $user->slug;           // вызовет buildSlug(), результат закэширован
echo $user->missing;        // null (ключ не существует)

isset($user->slug);         // true — карта учитывается в __isset
```

---

## 2. Публичные методы

### `__get(string $name): mixed`

Вызывается при чтении недоступного свойства.

| Параметр | Тип | Описание |
|----------|-----|----------|
| `$name` | `string` | Имя свойства |

**Логика (в этом порядке):**
1. Если существует метод `get{Name}Attribute()` — вызвать и вернуть результат. Карта при этом не строится.
2. Если `$name` есть в `attributeMap()` — вернуть значение из `$attribute_cache`, либо вычислить (и при `$cache === true` запомнить).
3. Иначе — вернуть `$this->attributes[$name] ?? null`.

```php
$value = $obj->someField; // эквивалент $obj->__get('someField')
```

---

### `__set(string $name, mixed $value): void`

Вызывается при записи в недоступное свойство. Всегда сбрасывает `$attribute_cache[$name]`.

| Параметр | Тип | Описание |
|----------|-----|----------|
| `$name` | `string` | Имя свойства |
| `$value` | `mixed` | Значение для записи |

**Логика:**
1. Если существует метод `set{Name}Attribute($value)` — вызвать его.
2. Иначе — записать в `$this->attributes[$name]`.

```php
$obj->someField = 'value'; // эквивалент $obj->__set('someField', 'value')
```

---

### `__isset(string $name): bool`

Вызывается при `isset($obj->name)`, `empty($obj->name)` и в операторе `??`.

Возвращает `true`, если существует аксессор `get{Name}Attribute()`, либо `$name` объявлен в `attributeMap()`, либо ключ есть в `$attributes`. Значение при этом **не вычисляется**.

> **Важно.** Без `__isset` PHP считает магическое свойство несуществующим: `$obj->name ?? 'default'` вернул бы `'default'`, даже когда аксессор есть, а `__get` не был бы вызван вовсе. Наличие `__isset` в трейте делает `??` и `isset()` осмысленными для всех классов-потребителей.

```php
isset($file->mtime);   // true — есть getMtimeAttribute()
$file->mtime ?? 0;     // реальный mtime, а не 0
isset($file->nothing); // false
```

---

### `__unset(string $name): void`

Удаляет ключ из `$attributes` и из `$attribute_cache`. Мутаторы не вызываются.

---

## 3. Расширение в классе-потребителе

### `protected attributeMap(): array`

Возвращает карту `'имя_атрибута' => [$resolver, bool $cache = false]`. Строится один раз за жизнь объекта и запоминается в `$attribute_map`.

**Как разрешается `$resolver`:**

| Тип | Поведение |
|---|---|
| `Closure` или массив-callable | Вызывается через `call_user_func()` |
| `string`, и в классе есть такой метод | Вызывается как `$this->{$resolver}()` |
| `string`, и в классе определён `__call` | Вызывается как `$this->{$resolver}()` — уйдёт в `__call` |
| `string` в остальных случаях | Вызывается как глобальная функция `$resolver()` |

Объект имеет приоритет над глобальной функцией. Это не педантизм: **имена функций в PHP регистронезависимы**, поэтому `function_exists('getType')` находит встроенный `gettype()`. Если бы приоритет был обратным, атрибут `'type' => ['getType']` вызвал бы `gettype()` без аргументов.

Следствие: в классе с `__call` (например `Storage\File`) строка **всегда** трактуется как метод объекта. Чтобы сослаться на глобальную функцию из такого класса, оберните её в замыкание:

```php
protected function attributeMap(): array {
    return [
        'free' => [fn() => disk_free_space('/'), false],
    ];
}
```

Массив-callable нужно оборачивать явно, иначе он будет разобран как пара `[$resolver, $cache]`:

```php
'x' => [[$obj, 'method'], true],   // верно
'x' => [$obj, 'method'],           // ошибка: $resolver === $obj, $cache === 'method'
```

**Флаг кэширования.** Кэшируйте только то, что не меняется за жизнь объекта, — пути, имена, производные строки. Всё, что читает файловую систему или внешнее состояние (`size`, `mtime`, `is_file`), оставляйте некэшируемым, иначе в долгоживущем процессе объект навсегда запомнит первый снимок.

---

### `protected purgeAttributes(): void`

Очищает `$attribute_cache`, не трогая `$attributes`. Вызывайте из своего `purge()`, когда объект должен забыть вычисленное. Так делают `Storage\File::purge()` и `Cache\CacheDriver::purge()`.

---

## 4. Паттерн аксессоров/мутаторов

| PHP-метод | Вызывается при | Пример |
|-----------|----------------|--------|
| `get{Name}Attribute(): mixed` | чтении `$obj->name` | `getCreatedAtAttribute()` |
| `set{Name}Attribute($v): void` | записи `$obj->name = $v` | `setEmailAttribute(string $v)` |

Имя метода формируется через `Main::studlyCase($name)`, поэтому snake_case-свойство отображается в StudlyCase-метод: `created_at` → `getCreatedAtAttribute()`, `createdAt` → тоже `getCreatedAtAttribute()`.

> **Важно:** аксессоры и мутаторы должны быть `protected` или `public` — `private` не будет найден через `method_exists`.

Аксессор всегда выигрывает у карты: если объявлены и `getFooAttribute()`, и ключ `'foo'` в `attributeMap()`, вызовется аксессор.

`__get`/`__set` срабатывают только для **свойств**, `__call` — только для **методов**, поэтому они никогда не конфликтуют. Настоящий публичный метод до магии не доходит вовсе.
