<!-- DOCGEN:START -->
# ToolDefinition.php
<!-- DOCGEN:END -->

`namespace ST_system\MCP\Tools`

## Назначение

`ToolDefinition` — цепочка описания инструмента-замыкания. Её возвращает
`Server::tool('name', fn (array $args, Context $ctx): Result => …)`.

| Метод | Что делает |
|---|---|
| `title(string)` | название для человека |
| `description(string\|string[])` | описание; строки списка склеиваются переводом строки |
| `input(array $properties)` | свойства параметров. `'required' => true` у свойства уходит в `required` схемы; `additionalProperties: false` |
| `schema(array)` | полная JSON Schema вместо `input()` |
| `rules(array)` | дополнительные правила `Rule` по именам параметров |
| `output(array)` | `outputSchema` |
| `annotations(array)`, `readOnly()`, `destructive()`, `idempotent()`, `openWorld()` | аннотации; у каждого метода-флага есть аргумент `bool $value = true` |
| `streams(bool = true)` | ответ SSE-потоком |
| `asksHuman(bool = true)` | пометка для справочника |
| `confirms()` | для `Context::confirm()`: параметр `confirm`, `streams`, `asksHuman`. Порядок с `input()` не важен |

`ClosureTool` — сам инструмент, который описывает цепочка. Замыкание обязано вернуть
`Result`, иначе вызов закончится «внутренней ошибкой».
