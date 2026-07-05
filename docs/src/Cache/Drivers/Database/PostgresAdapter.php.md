# PostgresAdapter


## 1. Концепция

`PostgresAdapter` — реализация `DatabaseAdapterInterface` для PostgreSQL через `PDO`. Принцип работы аналогичен `MysqlAdapter`, различаются SQL-синтаксис: кавычки `"table"` вместо backtick, `ON CONFLICT ... DO UPDATE SET` вместо `ON DUPLICATE KEY UPDATE`, `TEXT` вместо `LONGTEXT`.

Миграция создаёт `CREATE TABLE IF NOT EXISTS` и `CREATE INDEX IF NOT EXISTS`.

```php
$adapter = PostgresAdapter::connect([
    'engine'   => 'postgres',
    'host'     => '127.0.0.1',
    'port'     => 5432,
    'database' => 'myapp',
    'username' => 'user',
    'password' => 'secret',
    'table'    => 'cache_entries',
]);
```

## 2. Публичные методы

### `static isAvailable(): bool`
`class_exists(PDO::class) && in_array('pgsql', PDO::getAvailableDrivers())`

### `static connect(array $cfg): static`
Принимает `engine` `'pgsql'`, `'postgres'` или `'postgresql'`. Создаёт PDO-соединение и запускает миграцию.

### `__construct(\PDO $pdo, string $table = 'cache_entries')`
Принимает готовое PDO-соединение.

Методы `write`, `read`, `exists`, `delete`, `scan` реализуют контракт `DatabaseAdapterInterface`..php
