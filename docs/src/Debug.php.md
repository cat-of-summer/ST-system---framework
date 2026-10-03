<!-- DOCGEN:START -->
# Debug.php
<!-- DOCGEN:END -->

`final class Debug` (`ST_system\Debug`) — служебный dev-инструмент отладки: форматированный backtrace, именованные таймеры и бенчмарк, обёртка над `php -l` (линтер синтаксиса) и, отдельно, полноценный установщик глобального **error/exception/shutdown-handler'а** (`handleError()`) с подключаемыми "методами дампа" (файл, консоль браузера, email, исключение, инлайн-эхо) и системой событий, позволяющей приложению перехватить ошибку раньше дефолтного вывода.

Использует трейты `HasInstance` (приватный синглтон-конструктор + `getInstance()` — нужен для диспетчеризации dump-методов, см. `__callStatic`), `HasConfig` (конфиг с dot-путями и валидацией через `Rule`) и `HasStaticEvents` (статические подписки/`fire` на класс). События теперь статические: `Debug::on()`/`fire()` идут прямо через трейт, без обёртки над синглтоном. Зависит от `ST_system\Main` (`timestamp()`, `uuid()`, `preparePath()`) и `ST_system\Rule` (валидация конфигов внутри dump-методов и `getOutput()`).

## Конфиг по умолчанию

```php
[
    'format' => [
        'timestamp' => [
            'output' => 'd-m-Y H:i:s',   // формат даты в самом дампе (на экране/в файле как текст)
            'file'   => 'd-m-Y~H-i-s',   // формат даты для суффикса ИМЕНИ файла (toFile, timestamp=true)
        ],
        'output' => 'json_encode',       // дефолтный "дампер" содержимого
    ],
    'filesystem' => [
        'dir'      => '~logs',           // куда пишет toFile() и куда handleError() шлёт error_log
        'file'     => 'log.html',
        'max_size' => 2 * 1024 * 1024,   // потолок файла toFile(), байт; <= 0 — без ротации
        'keep'     => 0.5,               // какая доля max_size остаётся после обрезки
    ],
    'handle_error' => [
        'reporting' => ['level' => E_ALL],
        'error'     => ['level' => [E_WARNING, E_NOTICE, E_USER_ERROR, E_USER_WARNING,
                                     E_USER_NOTICE, E_RECOVERABLE_ERROR, E_DEPRECATED, E_USER_DEPRECATED]],
        'exception' => ['level' => [\Throwable::class]],
        'shutdown'  => ['level' => [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR]],
        'output'    => ['method' => 'toFile', 'display' => false, 'config' => ['append' => true]],
    ],
    'dump_methods' => [
        'toEmail' => function(string $output, array $config = []): bool {
            // mail($config['to'], $config['subject'] ?? 'dump_to_email_log', $output)
        },
    ],
]
```

Ключевой момент: severity PHP-ошибок разбит на **три независимых списка**, потому что модель ошибок PHP требует трёх разных механизмов перехвата —

- `handle_error.error.level` — что ловит `set_error_handler` (warnings, notices, user-ошибки, recoverable errors, deprecations);
- `handle_error.exception.level` — какие классы исключений ловит `set_exception_handler` (по умолчанию — все, `\Throwable::class`);
- `handle_error.shutdown.level` — "фатальные" severity (`E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`, `E_USER_ERROR`), которые PHP **не может** передать в `set_error_handler` и которые обнаруживаются только на shutdown через `error_get_last()`.

## Зарезервированные события

`on_error`, `on_exception`, `on_shutdown` — их нельзя триггерить вручную через `trigger()` (это защищено `HasStaticEvents::trigger()`, который проверяет `getReservedEvents()`), они файрятся только внутри самого `Debug` при обработке ошибок.

## Backtrace

```php
Debug::backtrace(): string
```

Форматирует `debug_backtrace()` в читаемый многострочный текст: `"Class->method() in /path/file.php on line 42.\n"`.

Режимы (`config`):

- `chain => true` (по умолчанию) — проходит **весь** стек вызовов, пропуская первые 2 фрейма (сам вызов `backtrace()` и его непосредственного вызывающего), с отступами по уровню вложенности и префиксом `↘`. Дополнительно можно урезать диапазон счётчиками `skip_start`/`skip_end`.

  ```php
  function a() { b(); }
  function b() { c(); }
  function c() { echo Debug::backtrace(); }
  a();
  // ↘ b() in .../file.php on line 2.
  //     ↘ a() in .../file.php on line 3.
  ```

- `chain => false` — берёт **один** фрейм на фиксированной глубине (`min(4, count-1)`). Этим режимом внутри пользуется `getOutput()`, когда дамп вызван без явного `backtrace => true` — чтобы не раздувать вывод целой цепочкой, а показать одну короткую строку "откуда вызвано".

## Таймеры и бенчмарк

```php
Debug::start('query');
// ... код ...
$seconds = Debug::finish('query'); // float, секунды
```

- `start(string $name = 'default')` — запоминает текущую метку времени под именем `$name`.
- `finish(string $name = 'default'): float` — возвращает разницу с моментом `start()` и **удаляет** запись таймера (одноразовый: повторный `finish()` с тем же именем бросит `InvalidArgumentException`, пока не будет нового `start()`).

```php
$stats = Debug::benchmark(fn() => usort($big, fn($a,$b)=>$a<=>$b), iterations: 20, warmup: 3);
// ['durations'=>[...], 'iterations'=>20, 'warmup'=>3,
//  'avg'=>..., 'median'=>..., 'min'=>..., 'max'=>..., 'total'=>..., 'unit'=>'s']
```

`benchmark()` сперва прогоняет `$job()` `$warmup` раз без замера, затем `$iterations` раз с замером через таймеры с уникальным префиксом (`Main::uuid()`), чтобы не столкнуться с одноимёнными таймерами, запущенными где-то параллельно. Медиана считается корректно и для чётного, и для нечётного числа итераций.

## Линтер

```php
Debug::linter(string $file_path): array
```

Запускает **только синтаксическую** проверку файла через `php -l` в дочернем процессе (`exec()`), учитывая текущее значение ini `short_open_tag` (читается один раз и мемоизируется статически), чтобы линтинг совпадал с реальными правилами парсинга рантайма.

```php
$r = Debug::linter('modules/Foo/index.php');
// ['ok' => true,  'result' => 'No syntax errors detected', 'errors' => [], 'code' => 0]
// либо
// ['ok' => false, 'result' => 'PHP Parse error: ...', 'errors' => [...], 'code' => 255]
```

Если файла не существует — возвращает `ok=false, code=1` без запуска подпроцесса. Обёрнут в `try/catch`: неожиданный сбой (например, `exec()` недоступен) даёт `code=-1`.

Практическое применение: `Loader::connect()` использует `Debug::linter()` перед `include`/`include_once` (но не перед `require`/`require_once`) — если линтинг не прошёл, `include` молча пропускается, а исключение при самом `include` тоже глушится. Это делает `include`-ветку загрузчика намеренно fault-tolerant/best-effort, в отличие от `require`, который падает как обычный PHP при ошибке.

## Общий формат дампа — `getOutput()`

Приватный метод-ядро, который использует каждый dump-метод. Собирает вывод из:

1. дампера, выбранного по `output_type` (`print_r`, `var_export`, `var_dump`, `json` — компактный JSON в одну строку, либо дефолт `json_encode` c `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`); вывод `var_dump` перехватывается через `ob_start()`, так как эта функция эхо-ит, а не возвращает строку;
2. отформатированного timestamp (`format.timestamp.output`), если `time` не `false`;
3. backtrace по `config['backtrace']`: `true` (или `'full'`) — полная цепочка, `'short'` (по умолчанию) — одна строка «откуда вызвано» (`chain => false`), `false` (или `'none'`) — строки нет;
4. опциональной обёртки в `<pre>...</pre>` (`config['pre']`, по умолчанию `true`).

Части 1–3 склеиваются через перевод строки, пустые пропускаются.

Управляющие ключи конфига, общие для всех dump-методов: `output_type`, `backtrace` (default `'short'`), `pre` (default `true`), `time` (default `true`), `timestamp_format_output`. Схема этих ключей одна, `outputSchema()`. Dump-метод с собственной схемой `applyConfig` обязан её подмешать (`$schema + static::outputSchema()`): `applyConfig` отбрасывает необъявленные ключи, и без этого `pre`/`backtrace`/`output_type` из вызова до вывода не дойдут.

## Dump-методы

Это приватные instance-методы, но вызываются как статические — благодаря `__callStatic()` (см. ниже), который форвардит `Debug::here($x)` в `getInstance()->here($x)` и т.п.

### `Debug::here($content, $config = [])` — инлайн-эхо

Классический "дампни прямо тут" (`dd()`-стиль):

```php
Debug::here($user, ['backtrace' => true]);
// выводит <pre> с таймстампом, полным backtrace и json_encode($user) </pre> прямо в текущем месте вывода
```

### `Debug::toConsole($content, $config = [])` — в консоль браузера

```php
Debug::toConsole(['step' => 1, 'payload' => $payload]);
// echo '<script>console.log(`...отформатированный дамп...`)</script>'
```

Внимание: содержимое не экранируется отдельно против script-injection сверх того, что уже делает выбранный дампер — это debug-инструмент, но если в `$content` может попасть недоверенный пользовательский ввод, стоит об этом помнить.

### `Debug::exception($content, $config = [])` — "дамп через исключение"

```php
Debug::exception($state);
// throw new \Exception($отформатированный_дамп);
```

Бросает обычное `\Exception`, чьё сообщение целиком — отформатированный дамп (timestamp + backtrace + содержимое). Удобно, чтобы прервать выполнение и передать debug-информацию через тот механизм отображения ошибок, который уже есть в приложении (например, через `handleError()` того же `Debug`).

### `Debug::toFile($content, $config = [])` — запись в лог-файл

```php
Debug::toFile(['error' => 'oops'], [
    'dir'       => '~logs/custom',
    'file'      => 'debug.html',
    'timestamp' => true,   // добавит суффикс вида debug_14-07-2026~10-23-05.html
]);
```

Путь строится через `Main::preparePath($config['dir'], 3)` (`filesystem.dir` по умолчанию `~logs`), имя файла — из `filesystem.file` (расширение по умолчанию `html`, если не указано), опционально с добавлением timestamp-суффикса (`config['timestamp']`, по умолчанию выключено).

Важный нюанс поведения: класс ведёт статический счётчик обращений на каждый путь (`$dumper_counter`) **в рамках текущего запуска/процесса**:

- на **первую** запись в данный путь за этот запуск используется `config['append']` (по умолчанию `true` — дописывать к тому, что осталось от прошлых запусков; `append => false` обрезает файл перед записью);
- на **все последующие** записи в тот же путь **в этом же запуске** всегда используется `config['merge']` (по умолчанию `true` — дописывать), а `append` больше не перепроверяется.

То есть при нескольких вызовах `toFile()` в один и тот же файл за один запрос первый вызов решает "перезаписать или дописать к тому, что было от прошлых запросов", а все следующие в рамках этого же запроса всегда дописываются (если не выставить `merge => false`).

#### Ротация

Файл не растёт выше `max_size` байт (по умолчанию `filesystem.max_size`, 2 МБ). Лимит задаётся и на вызов: `Debug::toFile($x, ['max_size' => 512 * 1024])`. Архивов нет: если очередная запись не помещается, от файла остаётся хвост примерно `max_size * keep` байт вместе с новой записью, остальное срезается.

- Хвост начинается с целой записи: со строки `<pre>` при `pre => true`; иначе со строки-метки времени той же формы, что у новой записи (цифры обобщаются, так что подходит формат `format.timestamp.output` по умолчанию); при `time => false` — с начала строки, то есть журнал JSONL режется по целым строкам.
- Дорогая операция (перечитать хвост и переписать файл) случается раз на ~`max_size * (1 - keep)` записанных байт. Остальные вставки — обычный append.
- Запись идёт под `flock(LOCK_EX)`, поэтому параллельные процессы не рвут друг другу записи и обрезку.
- Обрезается только файл, в который идёт текущая запись. С `timestamp => true` имя файла меняется каждую секунду, и ротацию по времени задаёт уже имя.
- `max_size <= 0` отключает ротацию.

Нативные записи PHP, которые `handleError()` направляет через `ini_set('error_log', …)` в тот же каталог, мимо `toFile()` и ротации не проходят.

#### Журнал событий одной строкой (JSONL)

```php
Debug::toFile(['event' => 'login', 'user' => $id], [
    'file'        => 'events.jsonl',
    'output_type' => 'json',  // компактный JSON
    'pre'         => false,
    'backtrace'   => false,   // без строки «откуда вызвано»
    'time'        => false,   // без строки времени — кладите время в сами данные
]);
// events.jsonl: {"event":"login","user":17}
```

Каждая запись — одна строка JSON, файл читается построчно без фильтрации. С `time => true` перед каждой записью идёт строка времени (две строки на запись).

### `Debug::toStream($content, $config = [])` — в поток процесса (docker logs)

```php
Debug::toStream(['order' => $id, 'status' => 'failed']);
// 01-10-2026 12:03:01 App\Orders->pay() in /app/Orders.php on line 42. {"order":17,"status":"failed"}

Debug::setConfig(['handle_error.output.method' => 'toStream']); // ошибки из handleError() — в docker logs
```

Одна запись — одна строка: по умолчанию `output_type => 'json'` (компактный JSON), `pre => false`, оставшиеся переводы строк схлопываются в пробел. Сборщики логов (docker, journald, Loki) режут поток по строкам, и многострочный дамп иначе распался бы на отдельные события.

`stream` — `stderr` (по умолчанию), `stdout` или любой URI потока PHP (`php://stdout`, путь к файлу). По умолчанию выбран stderr: в web-SAPI stdout FPM-воркера попадает в docker logs только при `catch_workers_output = yes` (в официальных образах `php:*-fpm` включено, и stderr идёт туда же), а у mod_php stderr попадает в error log Apache, который в образах `php:*-apache` выведен в docker logs. `php://output` для этого не годится: это тело ответа. В CLI оба потока выходят в терминал, их можно разделить перенаправлением.

### `Debug::toEmail($content, $config = [])` — пример кастомного dump-метода

Зарегистрирован из коробки в `dump_methods` как демонстрация формата:

```php
Debug::toEmail($exceptionDetails, ['to' => 'dev@example.com', 'subject' => 'Prod error']);
// mail($to, $subject, $отформатированный_дамп)
```

## `handleError()` — глобальный обработчик ошибок

```php
Debug::handleError([
    'onError'     => fn($error)     => Logger::warning($error['message']),
    'onException' => fn(\Throwable $th) => Sentry::capture($th),
    'onShutdown'  => fn($error)     => Alerts::pageOnCall($error),
    'display'     => false,               // ini display_errors / display_startup_errors
    'dir'         => '~logs',             // куда идёт и ini error_log, и fallback dump-метод
    'file'        => 'errors.html',
]);
```

Вызывается **только один раз за запрос** (`static $done`) — повторный вызов бросает `\LogicException`. Что делает:

1. регистрирует переданные `onError`/`onException`/`onShutdown` как слушателей событий `on_error`/`on_exception`/`on_shutdown` (сахар над `HasStaticEvents::on`);
2. выставляет `error_reporting()` в уровень из `handle_error.reporting.level` (по умолчанию `E_ALL`);
3. выставляет ini `display_errors`/`display_startup_errors` по `display` конфигу и форсирует `log_errors=1`;
4. направляет нативный ini `error_log` PHP на тот же файл (`dir`/`file`) — то есть встроенное логирование ошибок PHP тоже пишет в этот файл, независимо от собственного handler'а класса;
5. регистрирует `set_error_handler`, `set_exception_handler`, `register_shutdown_function` — каждый делегирует в приватные `onError`/`onException`/`onShutdown`.

### Схема "event-first, fallback-to-dump-method-second"

Для каждого из трёх каналов (`onError`, `onException`, `onShutdown`):

1. фильтр по настроенному severity/типу (`handle_error.error.level` / `.exception.level` / `.shutdown.level`) — если не подходит, ничего не происходит;
2. `fire('on_error'|'on_exception'|'on_shutdown', ...)` — если хоть один слушатель зарегистрирован, вызывается он и на этом обработка ошибки заканчивается;
3. если слушателей нет (`fire()` вернул `false`), выполняется fallback: вызывается dump-метод, настроенный в `handle_error.output.method` (по умолчанию `toFile`), с деталями ошибки/исключения и аргументами из `handle_error.output.config` (по умолчанию `['append' => true]`).

Fallback-метод может быть **любым** зарегистрированным dump-методом, включая кастомный, добавленный через `addDumpMethod()` — например, можно настроить `handle_error.output.method = 'toEmail'`, чтобы необработанные ошибки сразу летели на почту.

## `addDumpMethod()` — регистрация своего dump-метода

```php
Debug::addDumpMethod('toSlack', function(string $output, array $config = []): void {
    // $output уже полностью отформатирован (timestamp+backtrace+content) через getOutput()
    Http::post($config['webhook'], ['text' => $output]);
});

Debug::toSlack($payload, ['webhook' => $slackUrl]);
```

Бросает `\BadMethodCallException`, если имя коллизирует либо с уже существующим реальным методом инстанса, либо с уже зарегистрированным dump-методом — чтобы случайно не перезаписать существующее поведение.

## `__callStatic()` — диспетчер вызовов

1. если `$name` — реальный метод инстанса (`here`, `toConsole`, `toFile`, `exception`, ...) — форвардит `($arguments[0] ?? null, $arguments[1] ?? [])` в него; этим и объясняется, почему приватные instance-методы вызываются как `Debug::here(...)`;
2. иначе, если `$name` — зарегистрированный кастомный dump-метод (`dump_methods`) — сначала форматирует содержимое через `getOutput()` (кастомные dump-методы всегда получают **уже отформатированную строку**, не сырой контент — это видно по сигнатуре `toEmail(string $output, array $config = [])`), затем вызывает замыкание с `($output, ...остальные аргументы)`;
3. иначе — `\BadMethodCallException`.
