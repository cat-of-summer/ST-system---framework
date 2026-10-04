<!-- DOCGEN:START -->
# Tool.php
<!-- DOCGEN:END -->

`namespace ST_system\MCP\Tools`

## Назначение

`Tool` — абстрактная база инструмента-класса. Название, описание и схема уходят агенту в
`tools/list` и в справочник `ToolsDoc`, других копий этих текстов нет.

| Метод | Умолчание | Что задаёт |
|---|---|---|
| `name(): string` | обязателен | имя для вызова; префикс группы `Server::prefix()` добавляет сервер |
| `description(): string` | обязателен | для модели: когда вызывать, что вернёт, что делать дальше |
| `call(array $args, Context $context): Result` | обязателен | аргументы уже проверены, умолчания подставлены |
| `title(): string` | `name()` | короткое название для человека |
| `inputSchema(): array` | без параметров | JSON Schema (`type: object`); у каждого свойства — `description` |
| `rules(): array` | `[]` | дополнительные правила `Rule` по именам параметров (см. [[Args.php]]) |
| `outputSchema(): ?array` | `null` | JSON Schema для `structuredContent` |
| `annotations(): array` | `[]` | `readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint` |
| `asksHuman(): bool` | `false` | спрашивает ли человека (для справочника) |
| `streams(): bool` | `false` | отвечать ли SSE-потоком: инструмент спрашивает человека или долго ждёт |

Инструмент создаётся без аргументов (`new $class()`) на каждый запрос к серверу. Состояние
между вызовами в нём не хранят.
