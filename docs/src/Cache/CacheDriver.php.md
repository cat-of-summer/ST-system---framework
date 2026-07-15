<!-- DOCGEN:START -->
# CacheDriver.php
<!-- DOCGEN:END -->

`ST_system\Cache\CacheDriver` — абстрактный базовый класс для всех бэкендов кеша. Он реализует
драйвер-паттерн: сам класс даёт единый, финальный (неперегружаемый) публичный API
(`get`/`set`/`getMeta`/`setMeta`/`exists`/`isExpired`/`isValid`/`purge`/`purgeBase`/`purgeExpired`/
`purgeExpiredBase`), а конкретные наследники (`FileSystemCacheDriver`, `RedisCacheDriver` и т.д.)
реализуют только «сырые» операции хранилища.

Использовать напрямую этот класс не нужно — точка входа во всю систему кеширования это
`CacheManager` (см. `CacheManager.php.md`). `CacheDriver` документируется отдельно для тех, кто
пишет новый драйвер бэкенда.

## Ключ и мульти-файловость

Конструктор `__construct($key, array $config = [])` принимает ключ кеша (строка, массив,
объект — что угодно, что умеет хэшировать `Main::hash()`) и хэширует его в `$this->id`
(`md5`). Один экземпляр драйвера, привязанный к одному `id`, может хранить сразу несколько
именованных «файлов» (слотов) — например `data`, `meta` для чего-то ещё, `manifest` и т.п.
Имя файла по умолчанию берётся из `attributes['file']` (конфиг `file`, по умолчанию `'data'`),
но почти все публичные методы принимают `string $file = ''`, чтобы обратиться к другому слоту
в рамках того же ключа.

При конструировании: `applyConfig()` (см. `HasConfig`) заполняет конфиг значениями по умолчанию,
`Rule::scope(static::class, fn() => $this->__init($config))` вызывает инициализацию наследника,
после чего выставляются `attributes['file']` и `attributes['ttl']`.

## spawn() — переключение ключа без пересоздания соединения

`spawn($key, array $override = [])` клонирует текущий драйвер (`clone $this`), пересчитывает
`id` для нового ключа, чистит локальные in-memory кэши клона (`purge(false)` — без похода
в хранилище) и опционально переопределяет `file`/`ttl`. Затем вызывает `__rebind($override)` —
хук для наследников, которым нужно пересчитать что-то ещё (например `bucket` у Redis/Memcached/
Database, или `dir` у FileSystem), не переоткрывая соединение с бэкендом. Именно на этом
построен `CacheManager->make()` (инстанс-метод) — переключение ключа с переиспользованием уже
установленного соединения.

## Как написать новый драйвер

Нужно унаследоваться от `CacheDriver` и реализовать:

- `protected function __init(array $config): void` — инициализация из конфига (открыть
  соединение/подготовить путь и т.п.). Вызывается один раз в конструкторе внутри
  `Rule::scope(static::class, ...)`.
- `public function isAvailable(): bool` — быстрая проверка, что бэкенд реально пригоден к
  использованию прямо сейчас (директория создалась и доступна на запись, соединение поднялось,
  сессия активна и т.д.). `CacheManager` использует это для автоматического фолбэка на
  драйвер по умолчанию.
- Девять «сырых» примитивов хранения, все `protected`:
  - `writeBlob(string $file, string $payload): void`
  - `readBlob(string $file): ?string`
  - `writeMeta(string $file, array $meta): void`
  - `readMeta(string $file): ?array`
  - `blobExists(string $file): bool`
  - `purgeStorage(): void` — удалить всё, что относится к текущему ключу (`id`).
  - `purgeBaseStorage(): void` — удалить вообще все записи в этом пространстве драйвера
    (все ключи, не только текущий).
  - `purgeExpiredStorage(): void` — удалить текущий ключ, только если он просрочен.
  - `purgeExpiredBaseStorage(): void` — пройтись по всем ключам пространства и удалить
    только просроченные.

Опционально можно переопределить `protected function __rebind(array $override): void {}` —
он вызывается из `spawn()` после смены ключа/file/ttl, но до того как клон начнёт использоваться;
по умолчанию ничего не делает.

Конструктор, `spawn()` и весь публичный API (`get`/`set`/`getMeta`/`setMeta`/`exists`/`isExpired`/
`isValid`/`purge`/`purgeBase`/`purgeExpired`/`purgeExpiredBase`) объявлены `final` — их нельзя
переопределить в наследнике, это гарантирует одинаковое поведение (валидация TTL, кеширование
в памяти, сериализация) для любого бэкенда.

## Публичный API

- `set($data, int $ttl = 0, string $file = '', array $meta = [])` — сериализует `$data`
  (см. `encode()`/`decode()` ниже), пишет блоб через `writeBlob()` и мету через `setMeta()`
  (тип данных добавляется в мету автоматически), обновляет in-memory кеш (`data_cache`,
  `exists_cache`). `$ttl = 0` означает «взять TTL по умолчанию из конфига драйвера»
  (`attributes['ttl']`); TTL `-1` — «никогда не истекает».
- `get(string $file = '')` — сначала проверяет `isValid('expires_in', $file)`, при провале
  сразу возвращает `null` (даже не трогая хранилище). Если в памяти уже лежит значение с тем же
  `modified_at`, что и в актуальной мете — отдаёт его без чтения блоба. Иначе читает блоб и
  декодирует его согласно сохранённому типу.
- `setMeta(array $data, int $ttl = 0, bool $append = true, string $file = '')` — записывает
  метаданные записи. `$ttl = -1` → `expires_in = -1` (бессрочно). `$ttl > 0` и `expires_in` ещё
  не задан явно в `$data` → `expires_in = time() + $ttl`. При `$append = true` (по умолчанию)
  новые данные мержатся поверх текущей меты, а не заменяют её целиком. Всегда проставляет
  `modified_at` и `meta_modified_at` в `time()` при каждой записи.
- `getMeta(string $file = ''): array` — кешированное (в рамках экземпляра) чтение меты.
- `exists(string $file = ''): bool` — кешированная проверка наличия блоба.
- `isExpired(string $key = 'expires_in', string $file = ''): bool` — сравнивает
  `getMeta($file)[$key] ?? 0` с `time()`; значение `-1` трактуется как «никогда не истекает».
  Параметр `$key` позволяет проверять на просрочку не только сами данные (`expires_in`), но и
  произвольное поле меты той же семантики.
- `isValid(string $key = 'expires_in', string $file = ''): bool` — «не просрочено» И «физически
  существует» (`!isExpired() && exists()`).
- `purge(bool $storage = true)` — сбрасывает in-memory кеши (`data_cache`, `meta_cache`,
  `exists_cache`) и атрибуты; при `$storage = true` (по умолчанию) вызывает `purgeStorage()`,
  удаляя данные текущего ключа из бэкенда.
- `purgeBase(bool $storage = true)` — то же самое, но `purgeBaseStorage()`: удаляет ВСЁ
  пространство драйвера (все ключи), а не только текущий.
- `purgeExpired()` / `purgeExpiredBase()` — аналогично, но затрагивают только просроченные
  записи (`purgeExpiredStorage()` / `purgeExpiredBaseStorage()`).

## Сериализация (encode/decode)

`encode($data): array` возвращает `[тип, строка]`: объекты, реализующие `\JsonSerializable`,
сериализуются через `jsonSerialize()`, прочие объекты приводятся к массиву; массивы кодируются
в JSON (`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`); скаляры приводятся к строке.
Тип запоминается в мете (`meta['type']`) и используется при чтении в `decode($content, $type)`,
чтобы восстановить исходный PHP-тип (`array`/`object`/`integer`/`double`/`boolean`/`string`,
иначе — сырая строка как есть).
