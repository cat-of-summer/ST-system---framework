<!-- DOCGEN:START -->
# Database

## Файлы

- [DatabaseAdapterInterface.php](DatabaseAdapterInterface.php.md)
- [MysqlAdapter.php](MysqlAdapter.php.md)
- [PostgresAdapter.php](PostgresAdapter.php.md)
- [SqliteAdapter.php](SqliteAdapter.php.md)

<!-- DOCGEN:END -->

`DatabaseAdapterInterface` — контракт для SQL-адаптеров, на которых работает `DatabaseCacheDriver`: хранилище ключ-значение поверх обычной таблицы БД (`bucket`/`field` → `value`) с операциями `write`/`read`/`exists`/`delete`/`scan`.

Доступные реализации-адаптеры:

- **MysqlAdapter** — MySQL и MariaDB через расширение `pdo_mysql`.
- **PostgresAdapter** — PostgreSQL через расширение `pdo_pgsql`.
- **SqliteAdapter** — SQLite (файл на диске или `:memory:`) через расширение `pdo_sqlite`, без внешнего сервера БД.
