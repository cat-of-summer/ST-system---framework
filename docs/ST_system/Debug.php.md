# Debug.php

> Для AI-агентов: этот документ описывает **всю** публичную поверхность класса `Debug`.  
> Класс живёт в пространстве имён `ST_system`, файл `Debug.php`.

---

## 1. Концепция

`Debug` — **утилита отладки, логирования и профилирования**. Финальный класс, использует трейт `HasConfig`.

Ключевые идеи:

1. **Два режима использования.** Статический (`backtrace`, `start`, `finish`, `benchmark`, `linter`) и магический через `__callStatic` (когда нужен дамп/вывод значения).

2. **Магический `__callStatic`.** Любой неизвестный статический вызов создаёт экземпляр `Debug` с конфигом (второй аргумент) и вызывает одноимённый метод с первым аргументом.

3. **Основные on-instance методы.** `here` (эхо в HTML), `throw` (брасает Exception), `to_file` (запись в файл), `to_email` (отправка по почте), `to_console` (ввод через `console.log`).

**Дефолтная конфигурация** (переопределяется через `Debug::setConfig()`):

| Ключ | Значение | Описание |
|-----|---------|----------|
| `timestamp_format_output` | `'d-m-Y H:i:s'` | Формат даты в выводе |
| `timestamp_format_file` | `'d-m-Y~H-i-s'` | Формат даты в имени файла |
| `dir` | `'~logs'` | Директория для записи логов |
| `file` | `'log.html'` | Имя файла лога |
| `output_type` | `'json_encode'` | Способ сериализации: `json_encode`, `print_r`, `var_export`, `var_dump` |

```php
// Быстрый дамп в HTML:
Debug::here($someVar);

// Дамп в файл с настройкой:
Debug::to_file($someVar, ['dir' => '~/logs', 'file' => 'debug.html', 'merge' => true]);

// Бросить исключение с данными:
Debug::throw($data);

// Измерение времени:
Debug::start('my-op');
// ... работа ...
$seconds = Debug::finish('my-op'); // флоат, секунды
```

---

## 2. Публичные статические методы

### `static backtrace(array $config = []): string`

Трассировка стека вызовов в виде строки для дампа или лога.

| Ключ `$config` | Тип | Умолчание | Описание |
|----------------|-----|------------|----------|
| `chain` | `bool` | `true` | `true` — полный стек, `false` — только один фрейм |
| `skip_start` | `int` | `0` | Пропустить N фреймов сначала |
| `skip_end` | `int` | `0` | Пропустить N фреймов с конца |

**Возвращает:** `string` — многострочное описание стека.

```php
$trace = Debug::backtrace(['chain' => true, 'skip_start' => 1]);
// "   ⇘ SomeClass::method() in /path/to/file.php on line 42.\n"
```

---

### `static start(string $name = 'default'): void`

Запускает таймер с заданным именем. Использует `Main::timestamp()` для высокой точности.

---

### `static finish(string $name = 'default'): float`

Останавливает таймер и возвращает время выполнения в секундах.

**Бросает:** `InvalidArgumentException` если таймер с таким именем не был запущен.

```php
Debug::start('db-query');
// ... DB-запрос ...
$elapsed = Debug::finish('db-query'); // 0.0034 (секунды)
```

---

### `static benchmark(callable $job, int $iterations = 10, int $warmup = 0): array`

Измеряет производительность callable за N итераций.

| Параметр | Тип | Описание |
|----------|-----|----------|
| `$job` | `callable` | Измеряемая функция |
| `$iterations` | `int` | Количество измерений |
| `$warmup` | `int` | Калибровочные вызовы до старта замеров |

**Возвращает:** `array` со следующими ключами:

| Ключ | Тип | Описание |
|-----|-----|----------|
| `durations` | `float[]` | Время каждой итерации |
| `iterations` | `int` | Количество итераций |
| `warmup` | `int` | Сбросовых вызовов |
| `avg` | `float` | Среднее время |
| `median` | `float` | Медиана |
| `min` | `float` | Минимум |
| `max` | `float` | Максимум |
| `total` | `float` | Суммарное время |
| `unit` | `string` | `'s'` |

```php
$result = Debug::benchmark(function() {
    return array_sum(range(1, 1000));
}, 100, 5);

echo $result['avg'];    // среднее время в секундах
echo $result['median']; // медиана
```

---

### `static linter(string $file_path): array`

Проверяет синтаксис PHP-файла через `php -l`.

| Параметр | Тип | Описание |
|----------|-----|----------|
| `$file_path` | `string` | Путь к файлу (поддерживает `~`-нотацию) |

**Возвращает:** `array` с полями:

| Ключ | Тип | Описание |
|-----|-----|----------|
| `ok` | `bool` | `true` — ошибок нет |
| `result` | `string` | Последняя строка вывода `php -l` |
| `errors` | `string[]` | Список строк ошибок |
| `code` | `int` | Код возврата (`0` = ок) |

```php
$result = Debug::linter('~/src/MyClass.php');
if (!$result['ok']) {
    print_r($result['errors']);
}
```

---

### `static __callStatic(string $name, array $arguments): mixed`

**Магический вызов.** Создаёт экземпляр `Debug` с конфигом из `$arguments[1]` и вызывает на нём метод `$name` с значением `$arguments[0]`.

Доступные методы-назначения:

| Метод | Действие |
|--------|----------|
| `here` | Выводит `<pre>` в текущий ответ |
| `throw` | Бросает `Exception` со значением в сообщении |
| `to_file` | Записывает в файл |
| `to_email` | Отправляет email |
| `to_console` | Выводит через `console.log` |

```php
// Дамп в HTML:
Debug::here($data);

// Дамп в файл с настройкой:
Debug::to_file($data, [
    'dir'    => '~/logs',
    'file'   => 'debug.html',
    'merge'  => true,    // дописывать в рамках одного запроса
    'append' => false,   // перезаписывать файл при следующем запросе
    'backtrace' => true, // добавить стек вызовов
]);

// Бросить Exception:
Debug::throw($data);

// Отправить email:
Debug::to_email($data, ['to' => 'admin@example.com', 'subject' => 'error']);
```
