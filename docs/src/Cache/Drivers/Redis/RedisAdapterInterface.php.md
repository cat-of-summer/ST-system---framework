<!-- DOCGEN:START -->
# RedisAdapterInterface.php
<!-- DOCGEN:END -->

`interface RedisAdapterInterface` (`ST_system\Cache\Drivers\Redis`) — контракт для адаптеров Redis-семейства, на которых работает `RedisCacheDriver`. Хранилище использует Redis-хеши (`HSET`/`HGET`/`HEXISTS`): `key` — это бакет верхнего уровня (аналог `bucket` у Database-адаптеров), `field` внутри хеша — конкретный файл/блоб (данные, `.meta` и т.д.), значение — строка.

## Методы, которые обязан реализовать адаптер

- `public static function isAvailable(): bool` — проверка без подключения: установлено ли соответствующее расширение/библиотека клиента.
- `public static function connect(array $cfg): self` — фабричный метод, открывающий соединение по конфигу (`host`, `port`, `auth`, `db`) и возвращающий готовый адаптер.
- `public function hSet(string $key, string $field, string $value): void` — записывает поле хеша (upsert).
- `public function hGet(string $key, string $field)` — читает поле хеша; возвращает `false`, если поля/ключа нет.
- `public function hExists(string $key, string $field): bool` — проверяет наличие поля в хеше.
- `public function del($keys): void` — удаляет один ключ (весь хеш целиком) или массив ключей.
- `public function scan(&$cursor, string $pattern, int $count)` — постраничный курсорный обход ключей верхнего уровня (`SCAN` с `MATCH`/`COUNT`), совместимый по семантике с `DatabaseAdapterInterface::scan()`: возвращает `false`, если ничего не найдено на данном шаге, курсор `0` означает конец обхода.
