<!-- DOCGEN:START -->
# ClosureTool.php
<!-- DOCGEN:END -->

`namespace ST_system\MCP\Tools`

`ClosureTool` — инструмент из замыкания `fn (array $args, Context $ctx): Result`. Создаётся
`Server::tool('name', $handler)`, описывается цепочкой [[ToolDefinition.php]]. Если замыкание
вернуло не `Result`, бросается `UnexpectedValueException`, и модель получает «внутреннюю ошибку».
