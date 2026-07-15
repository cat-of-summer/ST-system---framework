<!-- DOCGEN:START -->
# MemcachedAdapterInterface.php
<!-- DOCGEN:END -->

`interface MemcachedAdapterInterface` (`ST_system\Cache\Drivers\Memcached`) — контракт для адаптеров Memcached-семейства, на которых работает `MemcachedCacheDriver`. В отличие от `DatabaseAdapterInterface`/`RedisAdapterInterface` (двумерная модель `bucket`/`field`), здесь модель плоская — единый строковый `key` на значение, как и сам протокол Memcached.

## Методы, которые обязан реализовать адаптер

- `public static function isAvailable(): bool` — проверка без подключения: установлено ли нужное PHP-расширение (`class_exists(...)`).
- `public static function connect(array $cfg): self` — фабричный метод, открывающий соединение(-я) с сервером(-ами) Memcached по конфигу (`host`/`port` либо список `servers`) и возвращающий готовый адаптер.
- `public function get(string $key)` — читает значение по ключу; возвращает `false`, если ключа нет (или значение реально хранит `false` — библиотека-клиент не различает эти случаи без доп. проверки кода результата).
- `public function set(string $key, string $value, int $expiry = 0): void` — записывает значение с TTL в секундах (`0` — бессрочно, в пределах ограничений самого Memcached).
- `public function touch(string $key, int $expiry): void` — обновляет TTL существующего ключа без изменения значения.
- `public function delete($keys): void` — удаляет один ключ или массив ключей.
- `public function exists(string $key): bool` — проверяет наличие ключа.
- `public function flush(): void` — полностью очищает все данные на подключённых серверах.
