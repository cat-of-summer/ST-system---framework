<!-- DOCGEN:START -->
# Channel.php
<!-- DOCGEN:END -->

`namespace ST_system\MCP\Transport`

`Channel` — поток сообщений сервера клиенту в рамках одного запроса: `send(array $message)`,
`ping()` (пустое событие, чтобы прокси не закрыл соединение), `closed()` (клиент ушёл).
Реализация для HTTP — [[SseChannel.php]]. В тестах — канал в память
(`tests/Support/MemoryChannel.php`), его передают фабрикой в `HttpTransport`.
