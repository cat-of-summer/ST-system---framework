<!-- DOCGEN:START -->
# ChannelElicitor.php
<!-- DOCGEN:END -->

`namespace ST_system\MCP\Elicitation`

`ChannelElicitor` — `elicitation/create` в SSE-поток текущего `tools/call` и ожидание ответа.

Вопрос уходит в поток того же запроса, а не в отдельный GET-поток сессии: так он связан с
вызовом и доходит до клиента, который GET-поток не открывал. Поле `mode` не передаётся: форма —
режим по умолчанию, а клиенты 2025-06-18 этого поля не знают.

Ответ клиент шлёт отдельным POST, его принимает другой воркер и кладёт в `PendingStore`.
Отсюда ответ забирается опросом раз в `poll_interval`. Пока идёт ожидание, в поток раз в
`ping_interval` уходит `ping`; если клиент ушёл, ожидание прекращается. Через
`elicitation_timeout` без ответа возвращается `timeout`.

Конструктор: `(Channel $channel, string $session, int $timeout = 300, float $poll = 0.25,
int $pingEvery = 15, ?callable $sleep = null)`. `$sleep` подменяют в тестах: в нём
«клиент» успевает ответить.
