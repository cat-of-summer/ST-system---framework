<!-- DOCGEN:START -->
# Memcached

## Файлы

- [MemcacheExtAdapter.php](MemcacheExtAdapter.php.md)
- [MemcachedAdapterInterface.php](MemcachedAdapterInterface.php.md)
- [MemcachedExtAdapter.php](MemcachedExtAdapter.php.md)

<!-- DOCGEN:END -->

`MemcachedAdapterInterface` — контракт для адаптеров Memcached-семейства, на которых работает `MemcachedCacheDriver`: плоское хранилище ключ-значение с TTL (`get`/`set`/`touch`/`delete`/`exists`/`flush`).

Доступные реализации-адаптеры:

- **MemcachedExtAdapter** — современное PECL-расширение `memcached` (обёртка над `libmemcached`, класс `\Memcached`); поддерживает persistent-соединения и SASL-аутентификацию.
- **MemcacheExtAdapter** — устаревшее PECL-расширение `memcache` (класс `\Memcache`); без persistent-соединений и SASL, `touch()` эмулируется перезаписью значения.
