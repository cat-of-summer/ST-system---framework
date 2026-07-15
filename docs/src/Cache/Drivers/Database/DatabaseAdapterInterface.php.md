<!-- DOCGEN:START -->
# DatabaseAdapterInterface.php
<!-- DOCGEN:END -->

`interface DatabaseAdapterInterface` (`ST_system\Cache\Drivers\Database`) — контракт для SQL-адаптеров, на которых работает [`DatabaseCacheDriver`](../DatabaseCacheDriver.php.md). Каждый адаптер оборачивает конкретную СУБД (через PDO) в единый набор из пяти операций над таблицей-хранилищем, устроенной как двумерный ключ `bucket` × `field` → `value`: `bucket` — это пространство имён (например, конкретный кеш-ключ верхнего уровня драйвера), `field` — имя конкретного файла/блоба внутри него (данные, `.meta` и т.д.).

## Методы, которые обязан реализовать адаптер

- `public static function isAvailable(): bool` — быстрая проверка без подключения: установлено ли нужное PDO-расширение/драйвер для конкретной СУБД (`\PDO::getAvailableDrivers()`). `DatabaseCacheDriver` вызывает её перед тем, как пытаться реально подключиться, чтобы отличить "СУБД недоступна на этом окружении" от ошибки конфигурации.
- `public static function connect(array $cfg): self` — фабричный метод: принимает конфиг соединения (`host`, `port`, `database`, `username`, `password`, `table`, для MySQL ещё `charset`) и возвращает готовый к работе инстанс адаптера. Реализация сама решает, как открыть `\PDO`-соединение и обычно сразу мигрирует (создаёт, если её ещё нет) таблицу-хранилище.
- `public function write(string $bucket, string $field, string $value): void` — записывает значение по паре `bucket`/`field`, перезаписывая существующее (upsert).
- `public function read(string $bucket, string $field)` — читает значение; возвращает `false`, если записи нет (а не `null`), чтобы отличать "нет значения" от пустой строки без доп. проверок.
- `public function exists(string $bucket, string $field): bool` — проверяет наличие записи без чтения самого значения.
- `public function delete($buckets): void` — удаляет все записи одного или нескольких `bucket` целиком (принимает как одну строку, так и массив строк).
- `public function scan(&$cursor, string $pattern, int $count)` — постраничный обход уникальных `bucket`, с курсором, glob-паттерном (`*`/`?`) и лимитом на страницу; возвращает `false`, если строк не найдено, иначе массив имён `bucket`. `$cursor` обнуляется до `0`, когда достигнут конец выборки — так вызывающий код (`purgeBaseStorage()`/`purgeExpiredBaseStorage()` в `DatabaseCacheDriver`) узнаёт, что обход завершён.

Название таблицы (`table`) во всех реализациях должно проверяться на безопасность как SQL-идентификатор (используется в интерполяции запроса, а не как bind-параметр), поскольку PDO не умеет параметризовать имена таблиц.
