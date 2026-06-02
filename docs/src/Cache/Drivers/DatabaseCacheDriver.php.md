# DatabaseCacheDriver

> Документация сгенерирована AI-агентом на основе исходного кода.

## 1. Концепция

`DatabaseCacheDriver` — кэш-драйвер, хранящий данные в реляционной базе данных. Поддерживает MySQL/MariaDB (через `MysqlAdapter`) и PostgreSQL (через `PostgresAdapter`). Данные хранятся в таблице с полями `bucket`, `field`, `value`.

**Особенности:**

- `engine` в конфиге выбирает адаптер (`'mysql'`/`'mariadb'` → `MysqlAdapter`, `'postgres'` → `PostgresAdapter`).
- При подключении автоматически запускается миграция (`CREATE TABLE IF NOT EXISTS`), создавая нужную таблицу.
- Соединения пулируются `static $pool` по хэшу параметров.
- Готовое соединение (`DatabaseAdapterInterface`) можно передать через `$config['connection']`.
- `bucket` = `<prefix><md5(key)>` — идентифицирует слот кэша; `field` = имя блоба или `<file>.meta`.
- `purgeBase()` итерирует по `bucket` через `scan()` с паттерном `<prefix>*`.

```php
$cache = new DatabaseCacheDriver('report:monthly', [
    'engine'   => 'mysql',
    'host'     => 'db.local',
    'database' => 'myapp',
    'username' => 'user',
    'password' => 'secret',
    'table'    => 'cache_entries',
    'ttl'      => 86400,
]);
$cache->set($reportData);
```

```php
// Передача готового соединения
$adapter = MysqlAdapter::connect($cfg);
$cache = new DatabaseCacheDriver('key', ['connection' => $adapter]);
```

## 2. Конфигурация

| Ключ | По умолчанию | Описание |
|---|---|---|
| `engine` | `null` | `'mysql'` / `'mariadb'` / `'postgres'` |
| `host` | `null` | Хост БД |
| `port` | `null` | Порт (по умолчанию специфичен для движка) |
| `username` | `null` | Пользователь БД |
| `password` | `null` | Пароль |
| `database` | `null` | Имя базы данных |
| `table` | `'cache'` | Имя таблицы |
| `charset` | `'utf8mb4'` | Кодировка (только MySQL) |
| `prefix` | `'cache:'` | Префикс bucket-ключей |
| `file` | `'data'` | Имя блоба по умолчанию |
| `ttl` | `3600` | TTL в секундах |
| `connection` | `null` | Готовый `DatabaseAdapterInterface` |

## 3. Публичные методы

### `isAvailable(): bool`

Возвращает `true`, если соединение с базой данных установлено.

Остальные публичные методы наследуются от `CacheDriver`: `set`, `get`, `setMeta`, `getMeta`, `exists`, `isExpired`, `isValid`, `purge`, `purgeBase`, `spawn`..php
