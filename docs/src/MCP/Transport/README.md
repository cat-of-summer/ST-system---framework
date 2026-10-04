<!-- DOCGEN:START -->
# Transport

## Файлы

- [Channel.php](Channel.php.md)
- [HttpTransport.php](HttpTransport.php.md)
- [SseChannel.php](SseChannel.php.md)

<!-- DOCGEN:END -->

Транспорт MCP: Streamable HTTP под php-fpm.

- **HttpTransport** — разбор POST/DELETE, сессии, выбор JSON или SSE.
- **Channel** — поток сообщений сервера клиенту в рамках одного запроса; **SseChannel** — его SSE-реализация. В тестах вместо него подставляют канал в память.
