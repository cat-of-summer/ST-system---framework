<!-- DOCGEN:START -->
# Redis

## Файлы

- [PhpRedisAdapter.php](PhpRedisAdapter.php.md)
- [PredisAdapter.php](PredisAdapter.php.md)
- [RedisAdapterInterface.php](RedisAdapterInterface.php.md)

<!-- DOCGEN:END -->

`RedisAdapterInterface` — контракт для адаптеров Redis-семейства, на которых работает `RedisCacheDriver`: хранилище на базе Redis-хешей (`hSet`/`hGet`/`hExists`/`del`/`scan`), где ключ хеша — бакет, а поле — конкретный файл/блоб.

Доступные реализации-адаптеры:

- **PhpRedisAdapter** — расширения `phpredis` (класс `\Redis`) либо `Relay` (класс `\Relay\Relay`) — быстрые клиенты на C.
- **PredisAdapter** — userland-библиотека `predis/predis` (`\Predis\Client`), не требующая компилируемого расширения.
