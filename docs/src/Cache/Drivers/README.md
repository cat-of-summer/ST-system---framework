<!-- DOCGEN:START -->
# Drivers

## Папки

- [Database](Database/)
- [Memcached](Memcached/)
- [Redis](Redis/)

## Файлы

- [DatabaseCacheDriver.php](DatabaseCacheDriver.php.md)
- [FileSystemCacheDriver.php](FileSystemCacheDriver.php.md)
- [MemcachedCacheDriver.php](MemcachedCacheDriver.php.md)
- [RedisCacheDriver.php](RedisCacheDriver.php.md)
- [SessionCacheDriver.php](SessionCacheDriver.php.md)

<!-- DOCGEN:END -->

Конкретные бекенды кеша, все наследуют `Cache\CacheDriver` и выбираются `CacheManager` по имени (`config('driver')`):

- **`FileSystemCacheDriver`** (`driver: 'filesystem'`) — дефолтный бекенд, хранит записи файлами на диске (с файловыми блокировками для конкурентного доступа).
- **`DatabaseCacheDriver`** (`driver: 'database'`) — хранит записи в SQL-базе; сам не работает с БД напрямую, а делегирует конкретному адаптеру из поддиректории `Database/` (MySQL/PostgreSQL/SQLite) по имени движка.
- **`MemcachedCacheDriver`** (`driver: 'memcached'`) — делегирует адаптеру из `Memcached/` (расширения `memcached` или `memcache`).
- **`RedisCacheDriver`** (`driver: 'redis'`) — делегирует адаптеру из `Redis/` (`phpredis`/`Relay` или `predis/predis`).
- **`SessionCacheDriver`** (`driver: 'session'`) — хранит записи прямо в PHP `$_SESSION`, без внешнего хранилища.

`DatabaseCacheDriver`/`MemcachedCacheDriver`/`RedisCacheDriver` держат пул соединений на процесс и предоставляют статический `disconnect()` — обязателен к вызову в дочернем процессе сразу после `pcntl_fork()`, чтобы не делить унаследованный сокет/дескриптор с родителем.
