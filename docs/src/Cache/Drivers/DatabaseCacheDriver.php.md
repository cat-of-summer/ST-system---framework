<!-- DOCGEN:START -->
# DatabaseCacheDriver.php
<!-- DOCGEN:END -->

`ST_system\Cache\Drivers\DatabaseCacheDriver` — драйвер кеша поверх SQL-базы данных. Сам
драйвер не знает деталей конкретной СУБД: он делегирует чтение/запись объекту, реализующему
`ST_system\Cache\Drivers\Database\DatabaseAdapterInterface` (адаптеры `MysqlAdapter`,
`PostgresAdapter`, `SqliteAdapter` и т.д. лежат в поддиректории `Database/` и документируются
отдельно).

## Выбор через конфиг

```php
CacheManager::make($key, [
    'driver'   => 'database',
    'engine'   => 'mysql',       // mysql|mariadb|postgres|sqlite|sqlite3, либо свой класс-адаптер
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'username' => 'user',
    'password' => 'secret',
    'database' => 'app',
    'table'    => 'cache',       // по умолчанию 'cache'
    'charset'  => 'utf8mb4',
    'prefix'   => 'cache:',
    'ttl'      => 3600,
]);

// либо готовое соединение (адаптер) напрямую, без параметров подключения:
CacheManager::make($key, ['driver' => 'database', 'connection' => $adapter]);
```

`engine` резолвится в класс адаптера через внутреннюю карту `ADAPTERS` (`mysql`/`mariadb` →
`MysqlAdapter`, `postgres` → `PostgresAdapter`, `sqlite`/`sqlite3` → `SqliteAdapter`); можно
также передать полное имя собственного класса адаптера в `engine`. Для `sqlite`/`sqlite3`
`host` не требуется — обязателен только `database` (путь к файлу или `:memory:`).

## Пул соединений и disconnect()

Соединения кешируются в статическом пуле процесса (`self::$pool`), ключ — хэш существенных
параметров подключения (`engine`/`host`/`port`/`database`/`username`/`table`), так что разные
экземпляры драйвера с одинаковыми параметрами переиспользуют один и тот же адаптер вместо
повторного подключения.

`DatabaseCacheDriver::disconnect()` — статический метод, роняющий соединения всего процесса
(следующее обращение переоткроет их лениво). **Обязателен к вызову в дочернем процессе сразу
после `pcntl_fork()`**: унаследованный дескриптор соединения с БД нельзя делить между
родителем и потомком — их байтовые потоки перемешаются и приведут к непредсказуемым ошибкам.
Соединения, переданные извне через `['connection' => $adapter]`, `disconnect()` не трогает —
это не собственность драйвера. Живые экземпляры драйвера отслеживаются через
`\WeakReference` (`self::$live`), чтобы не держать их «в живых» искусственно.

## Хранение данных

Каждый ключ кеша соответствует «бакету» — строке `prefix . hash(key)`. Внутри бакета
блоб и мета хранятся как отдельные записи адаптера: `write($bucket, $file, $payload)` /
`write($bucket, "$file.meta", $json)`, аналогично `read()`/`exists()`. Мета всегда сериализуется
в JSON перед записью.

`purgeBaseStorage()` и `purgeExpiredBaseStorage()` используют курсорное сканирование ключей
адаптера (`scan($cursor, prefix.'*', 500)`) — постранично находят все бакеты этого драйвера и
удаляют либо все (`purgeBase`), либо только с просроченным `expires_in` в мете
(`purgeExpiredBase`).
