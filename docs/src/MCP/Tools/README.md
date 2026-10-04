<!-- DOCGEN:START -->
# Tools

## Файлы

- [Args.php](Args.php.md)
- [ClosureTool.php](ClosureTool.php.md)
- [Tool.php](Tool.php.md)
- [ToolDefinition.php](ToolDefinition.php.md)
- [ToolError.php](ToolError.php.md)

<!-- DOCGEN:END -->

Инструменты MCP и проверка их аргументов.

- **Tool** — абстрактная база инструмента-класса.
- **ClosureTool** — инструмент из замыкания; **ToolDefinition** — цепочка его описания, её возвращает `Server::tool('name', fn …)`.
- **ToolError** — отказ операции, текст которого модель получит как есть.
- **Args** — компиляция `inputSchema` в `Rule` и проверка аргументов.
