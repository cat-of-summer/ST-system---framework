<!-- DOCGEN:START -->
# PredisAdapter.php
<!-- DOCGEN:END -->

`class PredisAdapter implements RedisAdapterInterface` (`ST_system\Cache\Drivers\Redis`) — адаптер для userland-клиента **`predis/predis`** (`\Predis\Client`) — чистой PHP-реализации протокола Redis, не требующей компилируемого расширения. Используется, когда `phpredis`/`Relay` недоступны (например, окружение без прав на установку PHP-расширений). `isAvailable()` проверяет `class_exists(\Predis\Client::class)` (наличие библиотеки через Composer).

## Подключение

```php
[
    'host' => '127.0.0.1',
    'port' => 6379,
    'auth' => 'secret',   // необязателен -> передаётся как 'password'
    'db'   => 0,          // необязателен -> передаётся как 'database', если не 0
]
```

`connect()` собирает параметры Predis-клиента `['host' => ..., 'port' => (int)...]`, добавляя `password` из `auth` (если задан) и `database` из `db` (если не `0`), и создаёт `new \Predis\Client($params)`.

## Особенности реализации

- Использует нижнерегистровые имена методов клиента (`hset`, `hget`, `hexists`, `del`, `scan`) — соглашение Predis, в отличие от camelCase у phpredis (`hSet`, `hGet` и т.д.).
- `hGet()` приводит `null` к `false` и явно кастует результат к `string` (Predis может возвращать не строго строковые типы в зависимости от конфигурации сериализации).
- `del()` распаковывает массив ключей в вариативные аргументы (`$this->client->del(...(array)$keys)`), поскольку метод Predis принимает ключи как отдельные аргументы, а не единым массивом.
- `scan()` нормализует `$cursor` (`null`/`false` → `0`), вызывает клиентский `scan($cursor, ['match' => $pattern, 'count' => $count])`, который возвращает пару `[$cursor, $keys]` (в отличие от phpredis, где курсор передаётся и обновляется по ссылке). Курсор приводится к `int`, а пустой список ключей превращается в `false`.
