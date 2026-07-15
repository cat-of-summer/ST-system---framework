<!-- DOCGEN:START -->
# MysqlAdapter.php
<!-- DOCGEN:END -->

`class MysqlAdapter implements DatabaseAdapterInterface` (`ST_system\Cache\Drivers\Database`) — SQL-адаптер кеша для **MySQL** и **MariaDB** через расширение `pdo_mysql`. `isAvailable()` проверяет, что класс `\PDO` существует и в списке `\PDO::getAvailableDrivers()` есть драйвер `mysql`.

## Подключение

`MysqlAdapter::connect($cfg)` принимает конфиг соединения:

```php
[
    'engine'   => 'mysql',        // или 'mariadb'; необязателен, но если указан — валидируется
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'app',          // обязателен
    'username' => 'root',
    'password' => '',
    'charset'  => 'utf8mb4',
    'table'    => 'cache_entries',
]
```

Если `engine` указан и это не `mysql`/`mariadb`, бросается `\RuntimeException` — так `DatabaseCacheDriver` не может случайно подключить `MysqlAdapter` к настроенному под другую СУБД конфигу. `database` обязателен (иначе `\InvalidArgumentException`). Собирается DSN `mysql:host=...;port=...;dbname=...;charset=...`, открывается `\PDO` с `ERRMODE_EXCEPTION`, отключёнными эмулированными präpared-запросами и `FETCH_ASSOC` по умолчанию. Сразу после подключения вызывается приватный `migrate()`, который выполняет `CREATE TABLE IF NOT EXISTS` — таблица кеша создаётся автоматически при первом использовании, миграции вручную не нужны.

## Особенности реализации

- Имя таблицы валидируется regex'ом `^[A-Za-z_][A-Za-z0-9_]*$` в конструкторе (`isValidIdentifier()`) — оно интерполируется прямо в SQL (через backtick-квотинг), поэтому не может быть параметризовано через bind, и небезопасное имя отклоняется на входе.
- `write()` — `INSERT ... ON DUPLICATE KEY UPDATE` по составному первичному ключу (`bucket`, `field`) — классический MySQL-upsert одним запросом.
- `read()` возвращает `false`, если строки нет или значение `NULL` — единообразно с остальными адаптерами БД.
- `scan()` транслирует glob-паттерн (`*` → `%`, `?` → `_`, экранирование `%`/`_`/`\`) в SQL `LIKE ... ESCAPE '\\'` и постранично выбирает **уникальные** `bucket` (`SELECT DISTINCT ... ORDER BY ... LIMIT ... OFFSET`), используя `$cursor` как числовой offset; когда возвращённых строк меньше запрошенного `$count`, курсор сбрасывается в `0` (конец выборки).
- Таблица создаётся с движком `InnoDB`, `utf8mb4`, составным первичным ключом `(bucket, field)` и дополнительным индексом по `bucket` — для быстрого `scan()`/`delete()` по бакету.
