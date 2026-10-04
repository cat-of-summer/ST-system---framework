<!-- DOCGEN:START -->
# Dispatcher.php
<!-- DOCGEN:END -->

`namespace ST_system\MCP`

## Назначение

`Dispatcher` — собранный MCP-сервер без транспорта: реестр инструментов и ответы на
сообщения JSON-RPC. Создаётся `Server::create()` или (на каждый запрос) `Server::route()`.

## Сведения о сервере (`$info`)

| Ключ | По умолчанию | Что задаёт |
|---|---|---|
| `name`, `version` | `'mcp'`, `'1.0.0'` | `serverInfo` |
| `title` | — | название для человека в `serverInfo` |
| `instructions` | — | строка или `callable(): string`. Клиент показывает её модели при подключении; пустая не отправляется. Здесь пишут порядок работы и правила, а не список инструментов |
| `protocols` | конфиг `Server` | версии протокола, новые первыми |

## Методы

- `add(Tool $tool, string $prefix = '', array $middlewares = [])` — регистрация (её делает `Server::tool()`).
- `tools(): array` — имя с префиксом → `Tool`; `tool(string $name): ?Tool`.
- `negotiate($requested): string` — версия клиента, если поддерживается, иначе последняя.
- `initializeResult(string $protocol)`, `toolsList()` — тела ответов. В `tools/list` пустые
  `properties` уходят объектом `{}`, в `annotations` добавляется `title`.
- `handle(array $request, Context $context): array` — ответ на запрос: `-32601` на неизвестный
  метод, `-32602` на неизвестный инструмент. Ошибки самого инструмента приходят результатом
  с `isError` (см. [[Server.php]]).
- `handleRequest(Request $request): Response` — Streamable HTTP через `HttpTransport`.
- `Dispatcher::result($id, $result)`, `Dispatcher::error($id, $code, $message)` — конверты JSON-RPC.

Константы `VERSIONS` и `LATEST` — версии протокола, которые знает эта версия фреймворка.
