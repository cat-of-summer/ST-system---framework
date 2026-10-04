<!-- DOCGEN:START -->
# SessionStore.php
<!-- DOCGEN:END -->

`namespace ST_system\MCP\State`

`SessionStore` — сессии MCP. Id (32 hex-символа) выдаётся на `initialize` и приходит обратно в
заголовке `Mcp-Session-Id`.

В сессии хранятся:
- согласованная версия протокола;
- `clientInfo` клиента;
- признак `elicitation` — умеет ли клиент формы.

`supportsForms()` признаёт форму, если клиент 2025-06-18 объявил `elicitation: {}`, а клиент
2025-11-25 указал `form` в списке режимов.

Активность отмечается mtime файла (`touch` на каждый запрос). Брошенные сессии и забытые
вопросы старше `session_ttl` удаляются на каждом `initialize`, cron для этого не нужен.
