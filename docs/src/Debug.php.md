# Debug.php

## 1. Концепция

`Debug` — **утилита отладки, логирования и профилирования**. Финальный класс, использует трейты `HasInstance` (синглтон), `HasConfig`, `HasEvents`.

Ключевые идеи:

1. **Два режима использования.** Статический (`backtrace`, `start`, `finish`, `benchmark`, `linter`) и магический через `__callStatic` (когда нужен дамп/вывод значения).

2. **Магический `__callStatic`.** Любой неизвестный статический вызов создаёт экземпляр `Debug` с конфигом (второй аргумент) и вызывает одноимённый метод с первым аргументом.

3. **Основные on-instance методы.** `here` (эхо в HTML), `throw` (бросает Exception), `toFile` (запись в файл), `toEmail` (отправка по почте), `toConsole` (вывод через `console.log`).

**Дефолтная конфигурация** (переопределяется через `Debug::setConfig()`):

| Ключ | Значение | Описание |
|-----|---------|----------|
| `format.timestamp.output` | `'d-m-Y H:i:s'` | Формат даты в выводе |
| `format.timestamp.file` | `'d-m-Y~H-i-s'` | Формат даты в имени файла |
| `format.output` | `'json_encode'` | Сериализация: `json_encode`, `print_r`, `var_export`, `var_dump` |
| `filesystem.dir` | `'~logs'` | Директория для записи логов |
| `filesystem.file` | `'log.html'` | Имя файла лога |

```php
// Быстрый дамп в HTML:
Debug::here($someVar);

// Дамп в файл с настройкой:
Debug::toFile($someVar, ['dir' => '~/logs', 'file' => 'debug.html', 'merge' => true]);

// Бросить исключение с данными:
Debug::throw($data);

// Измерение времени:
Debug::start('my-op');
// ... работа ...
$seconds = Debug::finish('my-op');
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

### `static on(string $event, callable $listener): void`

Регистрирует обработчик события. Обработчики накапливаются в singleton-инстансе.

События:

| Событие | Аргумент слушателя | Описание |
|---|---|---|
| `'on_error'` | `array $error` | PHP-ошибка, прошедшая через `set_error_handler` |
| `'on_exception'` | `\Throwable $th` | Неперехваченное исключение |
| `'on_shutdown'` | `array $error` | Фатальная ошибка при shutdown (`error_get_last()`) |

`array $error` для `on_error` и `on_shutdown` содержит ключи: `severity` / `type`, `message`, `file`, `line`.

Если слушатель не вернул `false` — дефолтный вывод (в файл) **не** выполняется. Если слушатель вернул `false` — дефолтный вывод срабатывает.

```php
Debug::on('on_error', function(array $error): void {
    error_log("PHP Error: {$error['message']} in {$error['file']}:{$error['line']}");
});

Debug::on('on_exception', function(\Throwable $th): void {
    // отправить в Sentry и т.д.
});
```

---

### `static handleError(array $config = []): void`

Устанавливает глобальные обработчики ошибок, исключений и shutdown. **Можно вызвать только один раз** — повторный вызов бросает `LogicException`.

Аргумент `$config` принимает быстрые ключи:

| Ключ | Тип | Описание |
|----------------|-----|----------|
| `onError` | `callable\|null` | Shortcut для `Debug::on('on_error', ...)` |
| `onException` | `callable\|null` | Shortcut для `Debug::on('on_exception', ...)` |
| `onShutdown` | `callable\|null` | Shortcut для `Debug::on('on_shutdown', ...)` |
| `display` | `bool` | `display_errors`. Умолч: `false` |
| `dir` | `string` | Директория лога PHP (`error_log`). Умолч: `~logs` |
| `file` | `string` | Имя файла лога. Умолч: `log.html` |

Фильтрация и поведение настраиваются через `Debug::setConfig()`:

| Ключ `setConfig` | Умолчание | Описание |
|---|---|---|
| `handle_error.reporting.level` | `E_ALL` | Передаётся в `error_reporting()` — какие ошибки PHP вообще генерирует |
| `handle_error.error.level` | `[E_WARNING, E_NOTICE, E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE, E_RECOVERABLE_ERROR, E_DEPRECATED, E_USER_DEPRECATED]` | Whitelist severity-кодов для `onError`. Ошибки вне списка молча игнорируются |
| `handle_error.exception.level` | `[\Throwable::class]` | Whitelist классов исключений для `onException`. Проверка через `instanceof` |
| `handle_error.shutdown.level` | `[E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR]` | Whitelist типов фатальных ошибок для `onShutdown` |
| `handle_error.output.method` | `'toFile'` | Метод вывода ошибки (любой dump-метод) |
| `handle_error.output.display` | `false` | `display_errors` |
| `handle_error.output.config` | `['append' => true]` | Конфиг, передаваемый в метод вывода вторым аргументом |

> **Почему `error.level` и `shutdown.level` не пересекаются:**  
> Фатальные ошибки (`E_ERROR`, `E_PARSE` и т.д.) завершают скрипт до вызова `set_error_handler` — `onError` их никогда не получает. `onShutdown` ловит их через `error_get_last()` после завершения.

```php
// В bootstrap.php — один раз:
Debug::handleError([
    'display' => false,
    'dir'     => __DIR__ . '/logs',
    'file'    => 'errors.log',
]);

// Исключить E_DEPRECATED и E_WARNING из лога:
Debug::setConfig([
    'handle_error.error.level' => [
        E_NOTICE,
        E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE,
        E_RECOVERABLE_ERROR, E_USER_DEPRECATED,
    ],
]);

// Логировать только исключения класса \RuntimeException и его потомков:
Debug::setConfig([
    'handle_error.exception.level' => [\RuntimeException::class],
]);
```

---

### `static addDumpMethod(string $name, \Closure $fn): void`

Регистрирует кастомный метод дампа, который затем вызывается через `__callStatic`.

| Параметр | Тип | Описание |
|----------|-----|----------|
| `$name` | `string` | Имя нового метода |
| `$fn` | `Closure` | `fn(string $output, array $config): mixed` |

**Бросает:** `BadMethodCallException` если метод с таким именем уже зарегистрирован.

```php
Debug::addDumpMethod('toSlack', function(string $output, array $config): void {
    $webhook = $config['webhook'] ?? '';
    file_get_contents($webhook, false, stream_context_create([
        'http' => ['method' => 'POST', 'content' => json_encode(['text' => $output])],
    ]));
});

// Использование:
Debug::toSlack($someVar, ['webhook' => 'https://hooks.slack.com/...']);
```

---

### `static __callStatic(string $name, array $arguments): mixed`

**Магический вызов.** Создаёт экземпляр `Debug` с конфигом из `$arguments[1]` и вызывает на нём метод `$name` с значением `$arguments[0]`.

Доступные методы-назначения:

| Метод | Действие |
|--------|----------|
| `here` | Выводит `<pre>` в текущий ответ |
| `throw` | Бросает `Exception` со значением в сообщении |
| `toFile` | Записывает в файл |
| `toEmail` | Отправляет email |
| `toConsole` | Выводит через `console.log` |

```php
// Дамп в HTML:
Debug::here($data);

// Дамп в файл с настройкой:
Debug::toFile($data, [
    'dir'    => '~/logs',
    'file'   => 'debug.html',
    'merge'  => true,    // дописывать в рамках одного запроса
    'append' => false,   // перезаписывать файл при следующем запросе
    'backtrace' => true, // добавить стек вызовов
]);

// Бросить Exception:
Debug::throw($data);

// Отправить email:
Debug::toEmail($data, ['to' => 'admin@example.com', 'subject' => 'error']);
```
