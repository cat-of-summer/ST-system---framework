# MysqlAdapter


## 1. Концепция

`MysqlAdapter` — реализация `DatabaseAdapterInterface` для MySQL / MariaDB через `PDO`. При создании соединения автоматически запускает `CREATE TABLE IF NOT EXISTS` со схемой `(bucket VARCHAR(190), field VARCHAR(190), value LONGTEXT, PRIMARY KEY(bucket, field))`.

Вставка/обновление использует `INSERT ... ON DUPLICATE KEY UPDATE`. Поиск `scan` работает через `DISTINCT bucket LIKE ... LIMIT ? OFFSET ?`, `*` в паттерне преобразуется в `%`.

```php
$adapter = MysqlAdapter::connect([
    'engine'   => 'mysql',
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'myapp',
    'username' => 'user',
    'password' => 'secret',
    'table'    => 'cache_entries',
    'charset'  => 'utf8mb4',
]);
```

## 2. Публичные методы

### `static isAvailable(): bool`
`class_exists(PDO::class) && in_array('mysql', PDO::getAvailableDrivers())`

### `static connect(array $cfg): static`
Создаёт PDO-соединение, запускает миграцию.

### `__construct(\PDO $pdo, string $table = 'cache_entries')`
Принимает готовое PDO-соединение (например, из пула соединений приложения). Имя таблицы валидируется регулярным выражением `[A-Za-z_][A-Za-z0-9_]*`.

Методы `write`, `read`, `exists`, `delete`, `scan` реализуют контракт `DatabaseAdapterInterface`..php
