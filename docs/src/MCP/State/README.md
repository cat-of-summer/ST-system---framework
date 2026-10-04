<!-- DOCGEN:START -->
# State

## Файлы

- [PendingStore.php](PendingStore.php.md)
- [SessionStore.php](SessionStore.php.md)
- [Storage.php](Storage.php.md)

<!-- DOCGEN:END -->

Файловое состояние MCP. Под php-fpm каждый HTTP-запрос живёт в своём процессе, общей памяти
у них нет. Ответ клиента на вопрос приходит отдельным POST в другой воркер, поэтому
состояние лежит в файлах в каталоге `Server::config('storage')`.

- **Storage** — атомарное чтение и запись JSON (tmp + rename), удаление старых файлов.
- **SessionStore** — сессии: `sessions/{id}.json`.
- **PendingStore** — вопросы, ждущие ответа: `pending/{session}/{sha1(id)}.json`.
