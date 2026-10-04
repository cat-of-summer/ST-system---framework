<!-- DOCGEN:START -->
# Server.php
<!-- DOCGEN:END -->

`namespace ST_system\MCP`

## Назначение

`Server` — объявление MCP-сервера (Model Context Protocol) в синтаксисе `Route`. Агент
(Claude Code и другие MCP-клиенты) подключается к маршруту по Streamable HTTP, получает список
инструментов и вызывает их. Модуль работает под обычным php-fpm, ему не нужны ни
долгоживущий процесс, ни WebSocket: сессии и ожидающие ответа вопросы лежат в файлах.

Что умеет сервер:
- протокол 2025-11-25, 2025-06-18, 2025-03-26: `initialize`, `ping`, `tools/list`, `tools/call`;
- инструменты — классом (`Tools\Tool`) или замыканием с fluent-описанием (`Tools\ToolDefinition`);
- проверка аргументов по JSON Schema инструмента через `Rule`, плюс свои правила `Rule`;
- группы с префиксом имён и middleware инструментов, как у `Route`;
- вопросы человеку (elicitation) прямо во время вызова и готовое подтверждение опасных операций
  `Context::confirm()`;
- справочник инструментов в markdown из живого сервера (`ToolsDoc`).

Чего нет: ресурсов, промптов, completions, server-initiated GET-потока и stdio-транспорта.

## Быстрый старт

```php
use ST_system\HTTP\Route;
use ST_system\MCP\Context;
use ST_system\MCP\Result;
use ST_system\MCP\Server;
use ST_system\MCP\Tools\ToolError;

Route::point('mcp')->group(function () {
    Route::middleware(RequireToken::class);            // HTTP-уровень: 401 до MCP

    Server::route('', [
        'name'         => 'notes',
        'version'      => '1.0.0',
        'title'        => 'Заметки',
        'instructions' => 'Сначала notes_list, затем notes_show по id.',
    ], function () {
        Server::tool(NotesList::class);                // класс-наследник Tools\Tool

        Server::tool('show', function (array $args, Context $ctx) {
            $note = Notes::find($args['id']);
            if ($note === null) throw new ToolError("Заметка {$args['id']} не найдена.");

            return Result::ok($note['title'], $note);
        })
            ->title('Заметка')
            ->description('Текст заметки по id из notes_list.')
            ->input(['id' => ['type' => 'string', 'description' => 'id заметки', 'required' => true]])
            ->readOnly();
    });
});
```

Клиент добавляется так (Claude Code):

```bash
claude mcp add --transport http notes https://example.com/mcp --header "Authorization: Bearer <токен>"
```

## Объявление

| Метод | Что делает |
|---|---|
| `Server::route(string $uri, array $info, callable $define): void` | Регистрирует `Route::any($uri, …)` в текущей группе `Route`: наследует её префикс и middleware. `$define` выполняется **лениво** — только на запрос к этому маршруту |
| `Server::create(array $info = [], ?callable $define = null): Dispatcher` | Тот же сервер без маршрута — для тестов и своих транспортов |
| `Server::tool(string\|Tool $tool, ?callable $handler = null)` | Инструмент: имя класса или экземпляр `Tool` (возвращает его), либо имя и замыкание `fn (array $args, Context $ctx): Result` (возвращает `ToolDefinition`) |
| `Server::prefix(string $prefix): self` + `->group(callable)` | Группа: префикс добавляется к именам инструментов, middleware родителя наследуются |
| `Server::middleware($middlewares): self` | Middleware инструментов до конца текущей группы — как `Route::middleware` |

`Server::tool()`, `prefix()` и `middleware()` вне замыкания `route()`/`create()` бросают
`LogicException`. Имя инструмента с префиксом — `[A-Za-z0-9_.-]{1,128}`, повтор имени —
`LogicException`.

`$info` (см. `Dispatcher`): `name`, `version`, `title`, `instructions` (строка или
`callable(): string` — вычисляется на `initialize`), `protocols`.

## Инструменты

**Класс** — для инструментов с логикой и длинными описаниями:

```php
use ST_system\MCP\Tools\Tool;

final class NotesList extends Tool {
    public function name(): string        { return 'notes_list'; }
    public function title(): string       { return 'Список заметок'; }
    public function description(): string { return 'Заметки, новые первыми. Текст — notes_show по id.'; }

    public function inputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20, 'description' => 'сколько вернуть']],
        ];
    }

    public function annotations(): array { return ['readOnlyHint' => true]; }

    public function call(array $args, Context $context): Result {
        return Result::ok('Заметки.', ['notes' => Notes::latest($args['limit'])]);
    }
}
```

Обязательны `name`, `description`, `call`; остальное имеет умолчания — см. [[Tool.php]].

**Замыкание** — для коротких инструментов; описание задаётся цепочкой (см. [[ToolDefinition.php]]):
`title`, `description`, `input` (свойства; `'required' => true` у свойства), `schema`
(полная JSON Schema), `rules`, `output`, `readOnly`, `destructive`, `idempotent`, `openWorld`,
`streams`, `asksHuman`, `confirms`.

**Результат** — `Result::ok($text, $data)` / `Result::error($text, $data)`. Текст — для модели:
что произошло и что делать дальше; `$data` уходит в `structuredContent` и дублируется JSON
в тексте.

**Ошибки** не превращаются в ошибки JSON-RPC — модель получает результат с `isError`:

| Источник | Текст для модели |
|---|---|
| аргументы не прошли проверку | `Неверные параметры: …` |
| `throw new ToolError($message, $data)` | `$message` как есть, `$data` — в `structuredContent` |
| любое другое исключение | `Внутренняя ошибка сервера: …`, плюс `error_log("[mcp] …")` |

## Проверка аргументов

`inputSchema` — контракт для клиента и источник проверки: [[Args.php]] компилирует её в
`Rule::object`. Понимаются `type` (в том числе список с `null`), `required`, `default`, `enum`,
`pattern`, `minLength/maxLength`, `minimum/maximum`, `minItems/maxItems`, `items`, вложенные
`properties`, `additionalProperties`. Неизвестные параметры отклоняются, если схема не
разрешает их явно. Строки `"20"` и `"true"` приводятся к целому и булеву.

То, чего в JSON Schema нет, задаётся правилами `Rule` по именам параметров — `Tool::rules()`
или `->rules([...])`:

```php
->rules(['email' => 'trim|email', 'name' => ['trim', Rule::create(fn (&$v) => $v !== 'root')]])
```

## Middleware инструментов

Middleware оборачивает вызов инструмента после проверки аргументов:

```php
Server::prefix('vault_')->group(function () {
    Server::middleware(function (array $args, Context $ctx, callable $next): Result {
        $ctx->set('vault', Vault::open($ctx->request()->headers('Authorization')));
        return $next($args, $ctx);
    });
    // инструменты группы читают $ctx->get('vault')
});
```

Формы — как у `Route`: класс со **статическим** `handle()`, callable или
`[middleware, ...аргументы]` (аргументы приходят после `$next`). Первый объявленный — снаружи.
Middleware может не вызывать `$next` и вернуть свой `Result`.

Проверку доступа ко всему серверу (токен, IP) удобнее делать middleware `Route` — до MCP,
с HTTP-статусом 401/403.

## Вопросы человеку и подтверждение

Инструмент со `streams()` отвечает SSE-потоком, если клиент принимает `text/event-stream`.
В поток уходят `elicitation/create` (форма) и `ping`, последним событием — результат. Ответ
человека клиент присылает отдельным POST, его забирает воркер, держащий поток. Подробности —
[[Context.php]] и папка `Elicitation`.

Опасная операция:

```php
Server::tool('delete', function (array $args, Context $ctx) {
    if ($refusal = $ctx->confirm("Удалить блок {$args['id']}?")) return $refusal;
    // …удаление
    return Result::ok('Удалено.');
})
    ->input(['id' => ['type' => 'string', 'description' => 'id блока', 'required' => true]])
    ->destructive()
    ->confirms();      // параметр confirm, SSE-ответ, пометка «спрашивает человека»
```

Клиент с elicitation получает вопрос «Да/Нет»: аргумент `confirm` тогда игнорируется, иначе
модель обходила бы вопрос сама. Клиенту без elicitation нужен `confirm: true` — модель
получает отказ с просьбой спросить человека в чате и повторить вызов.

## Конфиг

`Server::setConfig([...])` или файл конфига класса `ST_system\MCP\Server`:

| Ключ | По умолчанию | Что задаёт |
|---|---|---|
| `storage` | `~/storage/mcp` | каталог сессий и ожидающих ответа вопросов |
| `session_ttl` | `86400` | сколько живёт сессия без запросов, с; старые подчищаются на `initialize` |
| `elicitation_timeout` | `300` | сколько ждать ответа человека, с |
| `poll_interval` | `0.25` | как часто проверять, пришёл ли ответ, с |
| `ping_interval` | `15` | как часто слать `ping` в поток, пока ждём, с |
| `protocols` | `Dispatcher::VERSIONS` | поддерживаемые версии протокола, новые первыми |

## Развёртывание

- **nginx.** Отдельный location не нужен, но SSE-ответ не должен буферизоваться. Транспорт ставит
  `X-Accel-Buffering: no`. `fastcgi_read_timeout` должен быть больше `elicitation_timeout`.
- **Воркеры fpm.** Ожидание ответа человека держит воркер, поэтому на одновременные вопросы
  нужны свободные воркеры. Ответ приходит отдельным POST и тоже занимает воркер.
- **Фаервол.** Клиент шлёт `initialize`, `notifications/initialized` и `tools/list` подряд.
  Лимит запросов по IP (`Access::handleIp`) стоит пропускать для авторизованных запросов.

## Тестирование

```php
$dispatcher = Server::create(['name' => 'app'], function () { /* Server::tool(...) */ });

$response = $dispatcher->handle(
    ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'show', 'arguments' => ['id' => 'x']]],
    new Context(new NoElicitation())
);
```

HTTP без сервера — `new HttpTransport($dispatcher, fn () => $memoryChannel)` и
`->handle('POST', $json, $headers)`. Пример сквозного теста по настоящему HTTP —
`tests/Unit/MCP/McpHttpTest.php`.
