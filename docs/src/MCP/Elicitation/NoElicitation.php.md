<!-- DOCGEN:START -->
# NoElicitation.php
<!-- DOCGEN:END -->

`namespace ST_system\MCP\Elicitation`

`NoElicitation` — спросить человека нельзя: клиент не объявил elicitation или ответ идёт JSON
без потока. `supported()` возвращает `false`, `ask()` — `['action' => 'cancel']`. Инструменту
нужен запасной путь: `Context::confirm()` в этом случае требует аргумент `confirm: true`.
