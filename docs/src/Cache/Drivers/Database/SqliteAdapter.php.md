<!-- DOCGEN:START -->
# SqliteAdapter.php
<!-- DOCGEN:END -->

`class SqliteAdapter implements DatabaseAdapterInterface` (`ST_system\Cache\Drivers\Database`) — SQL-адаптер кеша для **SQLite** через расширение `pdo_sqlite`. `isAvailable()` проверяет наличие драйвера `sqlite` в `\PDO::getAvailableDrivers()`.

## Подключение

```php
[
    'engine'   => 'sqlite',   // или 'sqlite3'; обязателен и валидируется
    'database' => 'storage/cache/cache.sqlite', // путь к файлу БД, либо ':memory:'
    'table'    => 'cache_entries',
]
```

`engine` обязателен и должен быть `sqlite` или `sqlite3`, иначе `\RuntimeException`. `database` (в терминах общего конфига `DatabaseCacheDriver` — это путь к файлу, а не имя схемы) обязателен и не может быть пустой строкой (`\InvalidArgumentException`). Особый случай — `':memory:'`: тогда DSN становится `sqlite::memory:` без какой-либо работы с файловой системой. Для обычного пути: реальный абсолютный путь резолвится через [`ST_system\Storage\File`](../../Storage/File.php.md) (`File::make($path)->getPathname()` — поддержка `~/` и относительных путей), после чего, если директория назначения ещё не существует, она создаётся (`@mkdir(..., 0775, true)`). DSN собирается как `sqlite:{реальный_путь}`.

После открытия `\PDO` (с `ERRMODE_EXCEPTION` и `FETCH_ASSOC`, но **без** отключения эмуляции prepared statements — sqlite-драйвер PDO не поддерживает нативные prepared statements так, как MySQL/PostgreSQL) выполняются `PRAGMA journal_mode=WAL` (снижает блокировки при параллельном чтении/записи) и `PRAGMA busy_timeout=5000` (5 секунд ожидания снятия блокировки вместо мгновенной ошибки `SQLITE_BUSY`). Затем вызывается `migrate()`.

## Особенности реализации

- Имя таблицы валидируется тем же regex'ом, что и в остальных адаптерах БД, и интерполируется в двойных кавычках.
- `write()` — `INSERT ... ON CONFLICT ("bucket", "field") DO UPDATE SET "value" = excluded."value"` (SQLite поддерживает синтаксис `ON CONFLICT`, аналогичный PostgreSQL, начиная с версии 3.24).
- `read()`/`exists()`/`delete()`/`scan()` идентичны по структуре SQL-запросов `PostgresAdapter` (двойные кавычки, `LIKE ... ESCAPE '\\'`, `LIMIT`/`OFFSET` пагинация по уникальным `bucket`).
- `migrate()` создаёт таблицу (`TEXT`-колонки, составной первичный ключ) и отдельный индекс по `bucket` — оба через `CREATE ... IF NOT EXISTS`, безопасно при повторном вызове (переоткрытие соединения после `disconnect()` в `DatabaseCacheDriver`).
- Из всех адаптеров БД единственный, кто не требует отдельно работающего сервера — файл БД (или `:memory:`) создаётся и обслуживается в процессе PHP, что делает его удобным выбором для тестов и небольших/однопроцессных деплоев.
