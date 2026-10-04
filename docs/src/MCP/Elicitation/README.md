<!-- DOCGEN:START -->
# Elicitation

## Файлы

- [ChannelElicitor.php](ChannelElicitor.php.md)
- [Elicitor.php](Elicitor.php.md)
- [Forms.php](Forms.php.md)
- [NoElicitation.php](NoElicitation.php.md)

<!-- DOCGEN:END -->

Вопросы человеку через MCP-клиента (`elicitation/create`, режим формы) во время вызова
инструмента. Готовое подтверждение «Да/Нет» — `Context::confirm()`, свои вопросы —
`$ctx->elicitor->ask($message, $ctx->forms()->choice(...))`.

- **Elicitor** — интерфейс: `supported()` и `ask(string $message, array $requestedSchema): array`. Ответ — `['action' => accept|decline|cancel|timeout, 'content' => [...]]`.
- **ChannelElicitor** — вопрос в SSE-поток текущего вызова и ожидание ответа через `PendingStore`.
- **NoElicitation** — клиент не умеет спрашивать или ответ идёт без потока: `supported() === false`.
- **Forms** — схемы форм под версию протокола клиента и разбор напечатанных ответов.
