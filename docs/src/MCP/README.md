<!-- DOCGEN:START -->
# MCP

## Папки

- [Elicitation](Elicitation/)
- [State](State/)
- [Tools](Tools/)
- [Transport](Transport/)

## Файлы

- [Context.php](Context.php.md)
- [Dispatcher.php](Dispatcher.php.md)
- [Result.php](Result.php.md)
- [Server.php](Server.php.md)
- [ToolsDoc.php](ToolsDoc.php.md)

<!-- DOCGEN:END -->

Модуль `MCP/` — сервер Model Context Protocol поверх `HTTP\Route`. Через него агенты
(Claude Code и другие MCP-клиенты) вызывают инструменты приложения. Руководство с примерами —
[[Server.php]].

## Классы

В корне — то, что нужно коду приложения:
- **Server** — объявление сервера в синтаксисе `Route`: `Server::route()`, `tool()`, `prefix()`, `middleware()`, конфиг модуля;
- **Dispatcher** — собранный сервер: реестр инструментов и ответы на `initialize`, `ping`, `tools/list`, `tools/call`;
- **Context** — обстановка вызова инструмента: сессия, запрос, атрибуты middleware, вопросы человеку, `confirm()`;
- **Result** — результат инструмента: текст для модели и `structuredContent`;
- **ToolsDoc** — проверка полноты описаний и markdown-справочник инструментов.

Подпапки:
- **Tools/** — `Tool` (база инструмента-класса), `ClosureTool` и `ToolDefinition` (инструмент из замыкания), `ToolError`, `Args` (проверка аргументов через `Rule`);
- **Transport/** — `HttpTransport` (Streamable HTTP под php-fpm), `Channel` и `SseChannel` (поток ответа);
- **Elicitation/** — вопросы человеку: `Elicitor`, `ChannelElicitor`, `NoElicitation`, `Forms`;
- **State/** — файловое состояние между воркерами: `Storage`, `SessionStore`, `PendingStore`.

## Как классы связаны

`Server::route()` регистрирует маршрут `Route`. На запрос к нему он собирает `Dispatcher`
замыканием с `Server::tool()` и отдаёт запрос в `HttpTransport`. Транспорт ведёт сессию
(`SessionStore`) и выбирает формат ответа: JSON или SSE-поток (`SseChannel`). Затем он создаёт
`Context` и передаёт сообщение в `Dispatcher::handle()`.

`Dispatcher` проверяет аргументы (`Args` → `Rule`), прогоняет middleware и вызывает `Tool::call()`.
Если инструменту нужен ответ человека, `ChannelElicitor` шлёт `elicitation/create` в поток. Ответ
клиента приходит отдельным POST в другой воркер, и `PendingStore` передаёт его через файл.
