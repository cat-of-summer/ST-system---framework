<!-- DOCGEN:START -->
# MemcachedCacheDriver.php
<!-- DOCGEN:END -->

`ST_system\Cache\Drivers\MemcachedCacheDriver` — драйвер кеша поверх Memcached. Сам драйвер не
завязан на конкретное PHP-расширение: он делегирует операции объекту, реализующему
`ST_system\Cache\Drivers\Memcached\MemcachedAdapterInterface`, и автоматически выбирает первый
доступный адаптер из `MemcachedExtAdapter` (расширение `memcached`) и `MemcacheExtAdapter`
(расширение `memcache`) — они лежат в поддиректории `Memcached/` и документируются отдельно.

## Выбор через конфиг

```php
CacheManager::make($key, [
    'driver'        => 'memcached',
    'host'          => '127.0.0.1',
    'port'          => 11211,
    // либо несколько серверов:
    'servers'       => [['127.0.0.1', 11211], ['127.0.0.1', 11212]],
    'auth'          => null,
    'persistent_id' => null,
    'prefix'        => 'cache:',
    'ttl'           => 3600,
]);

// либо готовое соединение (адаптер) напрямую:
CacheManager::make($key, ['driver' => 'memcached', 'connection' => $adapter]);
```

## Пул соединений и disconnect()

Как и у `DatabaseCacheDriver`/`RedisCacheDriver`, соединения кешируются в статическом пуле
процесса по хэшу параметров подключения (`servers`/`host`/`port`/`auth`/`persistent_id`).
`MemcachedCacheDriver::disconnect()` роняет все пуловые соединения процесса (кроме внедрённых
извне через `connection`) — **обязателен к вызову в дочернем процессе сразу после
`pcntl_fork()`**, чтобы не делить унаследованный сокет с родителем. Это особенно важно для
соединений с `persistent_id`, которые переживают отдельный HTTP-запрос.

## Хранение данных и ограничения бэкенда

В отличие от Redis, Memcached — плоское key-value хранилище без структуры хеша и без
перечисления ключей, поэтому:

- Ключи строятся как плоские строки `bucket:file` (блоб) и `bucket:file.meta` (мета), где
  `bucket = prefix . hash(key)`.
- `writeMeta()` использует **нативный TTL memcached**: `expires_in` из меты конвертируется в
  TTL для `set()`/`touch()` (`-1` → `0`, то есть «без нативного TTL» на уровне memcached —
  инвалидация тогда полностью на совести `expires_in` в мете). При записи меты дополнительно
  вызывается `touch()` на ключе блоба, чтобы обновить и его нативный TTL.
- `purgeStorage()` умеет удалить только заведомо известные ключи текущего бакета (`file` и
  `data` — блоб и мета для обоих), поскольку перечислить все ключи бакета невозможно.
- `purgeBaseStorage()` **сбрасывает весь сервер целиком** (`flush()`) — прицельная очистка по
  префиксу технически недостижима в Memcached.
- `purgeExpiredBaseStorage()` — no-op: перечисление ключей недоступно, эвикцию просроченных
  записей выполняет сам Memcached по нативному TTL.
