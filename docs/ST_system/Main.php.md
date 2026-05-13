# Main.php

> Для AI-агентов: этот документ описывает **всю** публичную поверхность класса `Main`.  
> Класс живёт в пространстве имён `ST_system`, файл `Main.php`.

---

## 1. Концепция

`Main` — **статический класс-утилит (utility-class)**. Не инстанцируется. Собирает вспомогательные функции общего назначения, на которые ссылаются другие классы системы.

Группы методов:
- **Время и дата** — `timestamp()`, `pluralForm()`
- **Коллекции** — `merge()`, `dotGet()`, `dotSet()`, `dotFlatten()`
- **Сериализация** — `serialize()`, `deserialize()`, `hash()`
- **Идентификация** — `uuid()`
- **Пути** — `preparePath()`

---

## 2. Публичные методы

### `static timestamp(string $format = ''): string`

Возвращает текущее время с высокой точностью. Использует `hrtime()` если доступно, иначе `microtime()`.

| Параметр | Тип | Описание |
|----------|-----|----------|
| `$format` | `string` | Формат `date()`. При пустой строке возвращает timestamp в секундах (флоат). |

**Возвращает:** `string` — при пустом `$format` unix-timestamp с дробью, при заполненном — дата + микросекундная часть.

```php
Main::timestamp();             // '1715171234.123456789'
Main::timestamp('Y-m-d H:i:s'); // '2025-05-08 12:00:00.123456'
```

---

### `static pluralForm(mixed $n, array $forms): string`

Выбирает правильную форму слова для русского языка по правилам: 1/2–4/5+.

| Параметр | Тип | Описание |
|----------|-----|----------|
| `$n` | `mixed` | Число |
| `$forms` | `array` | Массив из 3 форм: `['яблоко', 'яблока', 'яблок']` |

**Возвращает:** `string` — одну из форм (0, 1 или 2 индекс).

```php
Main::pluralForm(1, ['день', 'дня', 'дней']);   // 'день'
Main::pluralForm(3, ['день', 'дня', 'дней']);   // 'дня'
Main::pluralForm(11, ['день', 'дня', 'дней']);  // 'дней'
Main::pluralForm(21, ['день', 'дня', 'дней']);  // 'день'
```

---

### `static merge(array ...$arrays): array`

Рекурсивно сливает несколько массивов. В отличие от `array_merge_recursive()`, правила:
- Скалярные значения заменяются (не объединяются в массив)
- Вложенные массивы мержатся рекурсивно
- `callable` + `callable` по одному ключу — объединяются в новую функцию, каскадно вызывающую оба

```php
$a = ['db' => ['host' => 'localhost', 'port' => 3306]];
$b = ['db' => ['port' => 5432, 'name' => 'mydb']];

Main::merge($a, $b);
// ['db' => ['host' => 'localhost', 'port' => 5432, 'name' => 'mydb']]
```

---

### `static serialize(mixed $value): string`

Сериализация значения в строку с **полным сохранением структуры и порядка** — пригодна для round-trip через `deserialize()`.

Поддерживает `string`, `int`, `float`, `bool`, `null`, `array`, `object`, `Closure`. Для `object` вызывает `jsonSerialize()` если реализован `\JsonSerializable`, иначе `(array)$obj`. Циклические ссылки в графе объектов поддерживаются (тег `r:N`). Замыкания кодируются маркером `c` — тело замыкания не сохраняется.

Строки кодируются с префиксом длины в байтах: `s:<len>:<bytes>`.

```php
Main::serialize(null);                       // 'n'
Main::serialize(42);                         // 'i:42'
Main::serialize('hello');                    // 's:5:hello'
Main::serialize(['b' => 2, 'a' => 1]);       // 'a:{ks:1:b=i:2,ks:1:a=i:1}' — порядок сохранён
```

---

### `static deserialize(string $s): mixed`

Обратная операция к `serialize()`. Восстанавливает значение по сериализованной строке.

Семантика — round-trip по значениям, типам и порядку:
- Скаляры (`int`, `float`, `bool`, `null`, `string`) и циклические ссылки восстанавливаются точно.
- Порядок элементов списков и ключей ассоц. массивов сохраняется.
- Замыкания восстанавливаются как пустышка `fn() => null` — тело потеряно при сериализации.
- Объекты восстанавливаются через `ReflectionClass::newInstanceWithoutConstructor()` (без вызова `__construct`); private/protected свойства заполняются через рефлексию. Если класса нет в текущем процессе — fallback на `stdClass` с исходными именами свойств (включая NUL-префиксы).

**Бросает:** `RuntimeException` при синтаксических ошибках формата или ссылке на неизвестный объект.

```php
$s = Main::serialize(['user' => 'ivan', 'roles' => ['admin', 'editor']]);
$v = Main::deserialize($s);
// $v === ['user' => 'ivan', 'roles' => ['admin', 'editor']]
```

---

### `static hash(mixed $value): string`

Канонизированная строка для построения **детерминированных идентификаторов** (например, кэш-ключей через `md5()`). В отличие от `serialize()`:

- **Списки и ключи ассоц. массивов сортируются** — `['a'=>1,'b'=>2]` и `['b'=>2,'a'=>1]` дают одинаковый результат.
- **Замыкания кодируются как `c:<lenFile>:<file>:<startLine>:<endLine>:<staticVars>`** — одно и то же замыкание (по позиции в исходниках и захваченным `use`-переменным) даёт одинаковый хеш между запусками.
- Объекты — по `get_class` и содержимому свойств; идентичные по содержимому объекты разных экземпляров дают одинаковый хеш.

**Операция необратимая** — `deserialize()` к выводу `hash()` неприменим.

```php
md5(Main::hash($cacheKey)); // стабильный кэш-ключ между запусками
Main::hash(['b' => 2, 'a' => 1]) === Main::hash(['a' => 1, 'b' => 2]); // true
```

---

### `static uuid(int $version = 7): string`

Генерирует UUID. Поддерживаются версии v4 (random) и v7 (сортируемый по времени).

| Параметр | Тип | Описание |
|----------|-----|----------|
| `$version` | `int` | `4` — random UUID, `7` (default) — monotonic-time UUID |

**Возвращает:** `string` в формате `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx`.

```php
Main::uuid();   // UUID v7: 0190d1fc-7700-7abc-9999-...
Main::uuid(4);  // UUID v4: полностью случайный
Main::uuid(7);  // UUID v7: сортируемый, идеально для PK в БД
```

---

### `static dotGet(array $data, string $path, mixed $default = null): mixed`

Читает значение из вложенного массива через dot-notation.

| Параметр | Тип | Описание |
|----------|-----|----------|
| `$data` | `array` | Исходный массив |
| `$path` | `string` | Dot-путь, например `'db.host'` |
| `$default` | `mixed` | Возвращается если путь не найден |

```php
$config = ['db' => ['host' => 'localhost', 'port' => 3306]];

Main::dotGet($config, 'db.host');           // 'localhost'
Main::dotGet($config, 'db.name', 'main');   // 'main' (нет ключа)
Main::dotGet($config, 'items.0');           // поддерживает цифровые индексы
```

---

### `static dotSet(array &$data, string $path, mixed $value): void`

Записывает значение во вложенный массив через dot-notation. Создаёт промежуточные массивы если они не существуют.

```php
$data = [];
Main::dotSet($data, 'db.host', 'localhost');
// $data === ['db' => ['host' => 'localhost']]
```

---

### `static dotFlatten(array $data, string $prefix = ''): array`

Разворачивает вложенный массив в плоский с dot-ключами.

```php
Main::dotFlatten(['a' => ['b' => ['c' => 1]]]);
// ['a.b.c' => 1]

Main::dotFlatten(['x' => 1, 'y' => 2], 'prefix');
// ['prefix.x' => 1, 'prefix.y' => 2]
```

---

### `static preparePath(string $path, int $depth = 0): string`

Нормализует путь до файла:
- `~/logs` → заменяет `~` на `DOCUMENT_ROOT` или `COMPOSER_ROOT`
- `logs/app` → относительный путь относительно вызывающего файла
- Разрешает `..` в пути

| Параметр | Тип | Описание |
|----------|-----|----------|
| `$path` | `string` | Исходный путь |
| `$depth` | `int` | Глубина в стеке вызовов для определения базовой директории. `0` = файл, откуда вызывается `preparePath`, `1` = его вызыватель и т.д. |

**Возвращает:** `string` — абсолютный путь без трейлинг слэша.

```php
Main::preparePath('~/logs');  // '/var/www/html/logs'
Main::preparePath('/abs/path'); // '/abs/path'
Main::preparePath('relative/dir', 0); // dirname(__FILE_OF_CALLED_FROM__) . '/relative/dir'
```
