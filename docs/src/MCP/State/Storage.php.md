<!-- DOCGEN:START -->
# Storage.php
<!-- DOCGEN:END -->

`namespace ST_system\MCP\State`

`Storage` — JSON-файлы в каталоге `Server::config('storage')` (по умолчанию `~/storage/mcp`,
`~` — корень приложения).

- `path`, `read`, `write`, `exists`, `delete` — по пути относительно каталога. Запись атомарна: временный файл и `rename`.
- `prune(string $relative, int $maxAge)` — удаляет файлы старше `$maxAge` секунд и опустевшие каталоги.
