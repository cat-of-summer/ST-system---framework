<!-- DOCGEN:START -->
# SseChannel.php
<!-- DOCGEN:END -->

`namespace ST_system\MCP\Transport`

`SseChannel` — SSE-ответ Streamable HTTP. Каждое сообщение — `event: message` с JSON в `data`,
`ping()` — комментарий `: ping`. После каждой записи сбрасываются все буферы вывода. Признак
ушедшего клиента — `connection_aborted()`. Заголовки ответа ставит `HttpTransport`.
