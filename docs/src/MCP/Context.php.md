<!-- DOCGEN:START -->
# Context.php
<!-- DOCGEN:END -->

`namespace ST_system\MCP`

## Назначение

`Context` — второй аргумент каждого инструмента и middleware: обстановка вызова.

| Член | Что даёт |
|---|---|
| `$elicitor` | как спросить человека (`Elicitation\Elicitor`); `supported()` — можно ли |
| `$protocol` | версия протокола, согласованная в `initialize` |
| `$stream` | `Transport\Channel` SSE-потока или `null`, если ответ идёт JSON (тогда долго ждать нельзя) |
| `session()`, `sessionId()` | сессия MCP: `id`, `protocol`, `elicitation`, `client` (clientInfo) |
| `request()` | `HTTP\Request`, с которым пришёл вызов (`null` вне HTTP) |
| `arguments()` | проверенные аргументы текущего вызова |
| `set()`, `get()`, `has()` | атрибуты: middleware кладёт, инструмент читает |
| `forms()` | `Elicitation\Forms` под версию протокола клиента |

## confirm()

```php
if ($refusal = $ctx->confirm('Удалить блок «db»?')) return $refusal;
```

Возвращает `null`, если человек подтвердил, иначе готовый `Result::error`:
- **клиент умеет elicitation** — задаётся вопрос-форма с полем boolean. Напечатанные ответы
  («да», «ок», «нет») тоже понимаются. Отказ и закрытый вопрос дают модели разные тексты:
  отказ окончателен, закрытый вопрос — повод спросить в чате. Аргумент `confirm` при этом не
  учитывается;
- **не умеет** (или ответ идёт без потока) — нужен аргумент `confirm: true`.

Инструменту с `confirm()` нужны параметр `confirm` в схеме (`Context::confirmProperty()`) и
`streams()`. В инструменте-замыкании обе вещи даёт `->confirms()`.
