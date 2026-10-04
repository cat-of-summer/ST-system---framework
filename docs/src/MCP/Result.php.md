<!-- DOCGEN:START -->
# Result.php
<!-- DOCGEN:END -->

`namespace ST_system\MCP`

## Назначение

`Result` — результат инструмента:
- `Result::ok(string $text, ?array $data = null)` — успех;
- `Result::error(string $text, ?array $data = null)` — отказ, который модель должна увидеть (`isError: true`).

Текст — первое, что читает модель, поэтому в нём пишут и что произошло, и что делать дальше.
`$data` уходит в `structuredContent` и ещё раз — JSON под текстом, для клиентов, которые
`structuredContent` не показывают. Пустой массив отправляется объектом `{}`.

Свойства `text`, `data`, `error` публичны — их удобно проверять в тестах.
