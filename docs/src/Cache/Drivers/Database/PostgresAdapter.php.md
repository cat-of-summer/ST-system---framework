<!-- DOCGEN:START -->
# PostgresAdapter.php
<!-- DOCGEN:END -->

`class PostgresAdapter implements DatabaseAdapterInterface` (`ST_system\Cache\Drivers\Database`) — SQL-адаптер кеша для **PostgreSQL** через расширение `pdo_pgsql`. `isAvailable()` проверяет наличие драйвера `pgsql` в `\PDO::getAvailableDrivers()`.

## Подключение

```php
[
    'engine'   => 'pgsql',   // допустимы также 'postgres' / 'postgresql'; обязателен и валидируется
    'host'     => '127.0.0.1',
    'port'     => 5432,
    'database' => 'app',    // обязателен
    'username' => 'postgres',
    'password' => '',
    'table'    => 'cache_entries',
]
```

В отличие от `MysqlAdapter`, здесь `engine` обязателен: если значение не входит в `['pgsql', 'postgres', 'postgresql']`, бросается `\RuntimeException`. `database` тоже обязателен (`\InvalidArgumentException`). DSN — `pgsql:host=...;port=...;dbname=...` (без charset — PostgreSQL сам определяет кодировку соединения по серверной). `\PDO` открывается с теми же флагами (`ERRMODE_EXCEPTION`, без эмуляции prepared statements, `FETCH_ASSOC`). После подключения вызывается `migrate()` — создаёт таблицу и индекс, если их ещё нет.

## Особенности реализации

- Имя таблицы валидируется тем же regex'ом, что и в `MysqlAdapter` (`isValidIdentifier()`), и интерполируется в SQL в двойных кавычках (стандарт идентификаторов PostgreSQL), а не в backtick.
- `write()` — `INSERT ... ON CONFLICT ("bucket", "field") DO UPDATE SET "value" = EXCLUDED."value"` — upsert по составному первичному ключу через PostgreSQL-специфичный синтаксис `ON CONFLICT`.
- `read()`/`exists()`/`delete()` семантически идентичны `MysqlAdapter`, но с двойными кавычками вокруг идентификаторов вместо backtick.
- `scan()` использует ту же логику glob→`LIKE`, что и `MysqlAdapter` (общий приватный `globToLike()`, независимая копия в каждом классе), постранично выбирая уникальные `bucket` через `LIMIT`/`OFFSET`.
- `migrate()` создаёт таблицу (`TEXT`-колонки, без движка/charset — они не нужны в PostgreSQL) и отдельным запросом — `CREATE INDEX IF NOT EXISTS "{table}_bucket_idx"` по `bucket`, поскольку PostgreSQL не поддерживает `KEY` внутри `CREATE TABLE`, в отличие от MySQL.
