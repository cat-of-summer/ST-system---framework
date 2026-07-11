# Main.php

## 1. Концепция

`Main` — **статический класс-утилит (utility-class)**. Не инстанцируется. Собирает вспомогательные функции общего назначения, на которые ссылаются другие классы системы.

Группы методов:
- **Время и дата** — `timestamp()`, `pluralForm()`
- **Форматирование** — `formatBytes()`
- **Коллекции** — `merge()`, `dotGet()`, `dotSet()`, `dotFlatten()`
- **Хеширование** — `hash()` (для round-trip значений используйте нативные `serialize()`/`unserialize()`)
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

### `static formatBytes(int|float $bytes, string $format = '', int $precision = 2): string|int|float`

Форматирует размер в байтах. Формат задаётся **списком единиц** — по аналогии с тем, как `DateInterval::format()` раскладывает длительность на составляющие.

| Параметр | Тип | Описание |
|----------|-----|----------|
| `$bytes` | `int\|float` | Размер в байтах |
| `$format` | `string` | Пустая строка — авторежим. Одна единица — число. Две и более — строка-разложение. |
| `$precision` | `int` | Знаков после запятой. Действует **только** в авторежиме. |

**Токены:** `PB`, `TB`, `GB`, `MB`, `KB`, `B` (регистр не важен). Синонимы `KiB`, `MiB`, `GiB` и т.д. эквивалентны `KB`, `MB`, `GB` — множители везде степени 1024. Всё, что не является токеном, попадает в вывод как есть; символ `\` экранирует следующую букву, как в `date()`.

**Три режима:**

| `$format` | Возвращает | Пример |
|---|---|---|
| `''` | `string` — крупнейшая единица, у которой значение `>= 1` | `'3.44 GB'` |
| один токен | `int` для `b`, иначе `float` | `3520.0` |
| два и более | `string` — каждой единице достаётся целая часть, остаток переносится ниже | `'3 GB 448 MB 0 KB'` |

```php
$b = 3690987520;

Main::formatBytes($b);              // '3.44 GB'   — авторежим
Main::formatBytes($b, 'mb');        // 3520.0      — число (float)
Main::formatBytes($b, 'b');         // 3690987520  — число (int)
Main::formatBytes($b, 'GB MB KB');  // '3 GB 448 MB 0 KB'
Main::formatBytes($b, 'GB, MB');    // '3 GB, 448 MB'
Main::formatBytes(1536);            // '1.5 KB'
Main::formatBytes(0);               // '0 B'
Main::formatBytes(1536, 'xyz');     // 1536 — нераспознанный формат отдаёт байты
```

Одна единица возвращает **число**, а не строку — на этом держится обратная совместимость `File::getSize('mb')` и `Mistral::getHistorySize('kb')`. Если нужна строка `'3 GB'`, добавьте вторую единицу или используйте авторежим.

Метод используют `File::getSize()`, `File::diskFreeSpace()`, `File::diskTotalSpace()` и `Mistral::getHistorySize()`.

---

### `const BYTE_UNITS: array`

Карта `'единица' => множитель` для `formatBytes()`. Порядок — от `pb` к `b`, авторежим перебирает её сверху вниз.

```php
Main::BYTE_UNITS['gb']; // 1073741824
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

> **Round-trip значений.** Отдельного `Main::serialize()`/`deserialize()` больше нет — они
> дублировали нативный `serialize()`, но хуже (теряли точность float, ссылки, магию
> `__serialize`/`__wakeup`, enum, DateTime). Для сериализации значений используйте нативные
> `\serialize()` / `\unserialize($s, ['allowed_classes' => …])` напрямую. `Main::hash()` (ниже)
> остаётся — это отдельный инструмент канонических ключей, не round-trip.

### `static hash(mixed $value): string`

Канонизированная строка для построения **детерминированных идентификаторов** (например, кэш-ключей через `md5()`). В отличие от нативного `serialize()`:

- **Списки и ключи ассоц. массивов сортируются** — `['a'=>1,'b'=>2]` и `['b'=>2,'a'=>1]` дают одинаковый результат.
- **Замыкания кодируются как `c:<lenFile>:<file>:<startLine>:<endLine>:<staticVars>`** — одно и то же замыкание (по позиции в исходниках и захваченным `use`-переменным) даёт одинаковый хеш между запусками.
- Объекты — по `get_class` и содержимому свойств; идентичные по содержимому объекты разных экземпляров дают одинаковый хеш.

**Операция необратимая** — по выводу `hash()` восстановить значение нельзя (это не сериализация).

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

### `static preparePath(string $path, int $depth = 0, bool $strict = false): string`

Нормализует путь до файла:
- `~/logs` → заменяет `~` на `DOCUMENT_ROOT` или `COMPOSER_ROOT`
- `logs/app` → относительный путь относительно вызывающего файла
- Схлопывает `..` в пути (в обычном режиме)

| Параметр | Тип | Описание |
|----------|-----|----------|
| `$path` | `string` | Исходный путь |
| `$depth` | `int` | Глубина в стеке вызовов для определения базовой директории. `0` = файл, откуда вызывается `preparePath`, `1` = его вызыватель и т.д. |
| `$strict` | `bool` | Если `true`, сегмент `..` бросает `InvalidArgumentException` вместо тихого схлопывания. Для резолва путей из недоверенного ввода (например, имя вида в `View`), чтобы `../../` не вышел за пределы базовой директории. |

**Возвращает:** `string` — абсолютный путь без трейлинг слэша.

```php
Main::preparePath('~/logs');           // '/var/www/html/logs'
Main::preparePath('/abs/path');        // '/abs/path'
Main::preparePath('relative/dir', 0);  // dirname(__FILE_OF_CALLED_FROM__) . '/relative/dir'
Main::preparePath('/a/b/../c');         // '/a/c'  (схлопывает)
Main::preparePath('/a/b/../c', 0, true); // бросает InvalidArgumentException
```
