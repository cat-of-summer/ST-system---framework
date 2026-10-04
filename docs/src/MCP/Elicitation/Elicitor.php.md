<!-- DOCGEN:START -->
# Elicitor.php
<!-- DOCGEN:END -->

`namespace ST_system\MCP\Elicitation`

`Elicitor` — интерфейс вопроса человеку:
- `supported(): bool` — можно ли спросить;
- `ask(string $message, array $requestedSchema): array` — ответ человека.

Значения `action` в ответе:

| `action` | Что произошло |
|---|---|
| `accept` | человек заполнил форму, ответ в `content` |
| `decline` | человек явно отказался |
| `cancel` | закрыл окно, или клиент вернул ошибку |
| `timeout` | не ответил за `elicitation_timeout`, или клиент отключился |

Реализации — [[ChannelElicitor.php]] и [[NoElicitation.php]]. В тестах удобна своя
реализация со сценарием ответов (`tests/Support/FakeElicitor.php`).
