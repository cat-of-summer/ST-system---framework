<!-- DOCGEN:START -->
# HttpTransport.php
<!-- DOCGEN:END -->

`namespace ST_system\MCP\Transport`

## Назначение

`HttpTransport` — Streamable HTTP для php-fpm: один POST — одно сообщение JSON-RPC.
Обычно его создаёт `Dispatcher::handleRequest()`. Напрямую — в тестах:
`new HttpTransport($dispatcher, fn () => $channel)`, затем `->handle($method, $body, $headers)`
(имена заголовков в нижнем регистре).

## Поведение

| Запрос | Ответ |
|---|---|
| POST `initialize` | JSON, новая сессия в заголовке `Mcp-Session-Id` |
| POST без `Mcp-Session-Id` | 400, `-32000` |
| POST с неизвестной или истёкшей сессией | 404, `-32001` — клиент переподключится |
| уведомление (без `id`) | 202 без тела |
| ответ клиента на `elicitation/create` (без `method`) | 202; ответ кладётся в `PendingStore`, если вопрос был задан |
| запрос | JSON |
| `tools/call` инструмента со `streams()`, `Accept` содержит `text/event-stream` | SSE-поток (`X-Accel-Buffering: no`): вопросы и `ping`, последним событием — результат |
| тело не JSON | 400, `-32700` |
| пакет (JSON-массив) или не JSON-RPC 2.0 | 400, `-32600` (пакеты убраны из протокола в 2025-06-18) |
| GET и прочие методы | 405, `Allow: POST, DELETE` |
| DELETE | 204 (сессия закрыта) или 404 |

Elicitation в потоке включается, только если клиент объявил её в `initialize` (форма —
`elicitation: {}` или `elicitation.form`). На время потока снимается лимит времени скрипта
и включается `ignore_user_abort`; обрыв соединения проверяется через `Channel::closed()`.
