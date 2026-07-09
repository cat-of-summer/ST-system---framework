# Manager


## 1. Концепция

`Manager` — финальный класс-фасад для системы кэширования. Он не является самостоятельным хранилищем — за реальную работу отвечает один из драйверов (`FileSystemCacheDriver`, `RedisCacheDriver`, `DatabaseCacheDriver`). `Manager` выбирает нужный драйвер при создании, проверяет его доступность и при необходимости откатывается на драйвер по умолчанию.

**Ключевые идеи:**

- **Выбор драйвера** — задаётся ключом `'driver'` в `$config` при создании экземпляра. Значение может быть алиасом (`'redis'`) или полным именем класса.
- **Fallback** — если запрошенный драйвер недоступен (`isAvailable() === false`), автоматически используется `drivers.default` (по умолчанию `FileSystemCacheDriver`).
- **Статические методы-ярлыки** — `Manager::get()`, `Manager::set()`, `Manager::remember()` и другие создают экземпляр на лету; не нужно явно создавать объект.
- **`remember()`** — паттерн «получи из кэша или вычисли и сохрани». Умеет инвалидироваться не только по TTL, но и по **штампу** (см. ниже).
- **Маршрутизация конфига** — `Manager::setConfig()` умно распределяет параметры: ключи вида `'drivers.redis.*'` уходят напрямую в `RedisCacheDriver::setConfig()`, остальные — в сам `Manager`.

```php
// Конфигурация один раз при старте
Manager::setConfig([
    'drivers' => [
        'default' => 'filesystem',
        'filesystem' => ['dir' => '~/cache/', 'ttl' => 3600],
        'redis'      => ['host' => '127.0.0.1', 'port' => 6379],
    ]
]);

// Статические ярлыки
Manager::set('user:42', ['name' => 'Иван'], ttl: 600);
$data = Manager::get('user:42');

// Remember: вернуть из кэша или вычислить
$data = Manager::remember('report:2024', function() {
    return computeExpensiveReport();
}, ttl: 3600);

// Явный выбор драйвера
$redis = new Manager('session:xyz', ['driver' => 'redis']);
$redis->set(['token' => '...']);
```

## 2. Конфигурация

| Ключ | По умолчанию | Описание |
|---|---|---|
| `drivers.default` | `FileSystemCacheDriver::class` | Класс или алиас драйвера по умолчанию |
| `drivers.available.filesystem` | `FileSystemCacheDriver::class` | Алиас → класс |
| `drivers.available.redis` | `RedisCacheDriver::class` | Алиас → класс |
| `drivers.available.database` | `DatabaseCacheDriver::class` | Алиас → класс |

Ключи вида `drivers.<alias>.<param>` в `setConfig()` автоматически передаются в соответствующий класс драйвера.

## 3. Публичные методы

### `static setConfig(array $config): void`
Специальный метод конфигурации. Распределяет параметры: часть уходит в `Manager`, часть — напрямую в классы драйверов через их `setConfig()`.

```php
Manager::setConfig([
    'drivers' => [
        'default' => 'redis',
        'redis'   => ['host' => 'redis.local', 'ttl' => 7200],
    ]
]);
```

---

### `__construct($key, array $config = []): void`
Создаёт экземпляр `Manager`, выбирает и инициализирует подходящий драйвер.

| Параметр | Описание |
|---|---|
| `$key` | Идентификатор кэша (любой тип) |
| `$config['driver']` | Алиас или класс драйвера |
| Остальные `$config.*` | Передаются драйверу |

```php
$cache = new Manager('page:home', ['driver' => 'redis', 'ttl' => 300]);
```

---

### `static get(mixed $key, string $file = ''): mixed`
Статический ярлык: создаёт экземпляр и вызывает `get()`.

```php
$data = Manager::get('user:42');
$html = Manager::get('page:home', file: 'html');
```

---

### `static set(mixed $key, mixed $data, string $file = '', int $ttl = 0): void`
Статический ярлык: создаёт экземпляр и вызывает `set()`.

```php
Manager::set('user:42', $userData, ttl: 3600);
```

---

### `static remember(mixed $key, callable $cb, int $ttl = 0, string $file = ''): mixed`
Возвращает значение из кэша, или выполняет `$cb()`, сохраняет результат и возвращает его.

```php
$result = Manager::remember('stats', fn() => DB::getStats(), ttl: 600);
```

---

### `static getMeta(mixed $key, string $file = ''): array`
Статический ярлык для `getMeta()`.

---

### `static setMeta(mixed $key, array $data, string $file = '', int $ttl = 0): void`
Статический ярлык для `setMeta()`.

---

### `remember(callable $cb, int $ttl = 0, string $file = '', mixed $stamp = null): mixed`
**Метод экземпляра.** Возвращает значение из кэша, иначе выполняет `$cb()`, сохраняет результат и возвращает его.

| Параметр | Описание |
|---|---|
| `$cb` | Вычисление значения при промахе |
| `$ttl` | Срок жизни. `0` — из конфига, `-1` — вечно |
| `$file` | Имя блоба внутри ключа |
| `$stamp` | Штамп актуальности. `null` — обычное TTL-поведение |

**Штамп** — произвольное скалярное значение, которое кладётся в метаданные *этой же* записи под ключом `stamp` и сверяется при чтении. Если сохранённый штамп не совпадает с переданным, запись считается устаревшей и `$cb()` выполняется заново, **независимо от TTL**.

Это позволяет привязать производный кэш к источнику, не спрашивая ни у кого его метаданные:

```php
// Результат парсинга шрифта хранится вечно, но пересчитывается,
// как только меняется mtime исходного файла
$meta = $cache->remember(
    fn() => $this->parseBinary(),
    -1,                    // не протухает по времени
    '',
    $this->file->mtime     // ...но протухает по изменению источника
);
```

При `$stamp === null` поведение полностью прежнее — только TTL.

> `remember()` не отличает закэшированный `null` от промаха: `get()` возвращает `null` в обоих случаях, поэтому `$cb`, возвращающий `null`, будет вызываться каждый раз.

Если производный артефакт — это **файл** (минифицированный CSS, сконвертированная картинка), а не значение, `remember()` не подходит: наружу нужен путь. Тогда штамп пишут вручную через четвёртый аргумент `set()`:

```php
if (!is_file($cache->file) || ($cache->getMeta()['stamp'] ?? null) !== $source->mtime)
    $cache->set($data, 0, '', ['stamp' => $source->mtime]);
```

---

### Методы экземпляра (проксируются к драйверу)

Экземпляр `Manager` поддерживает все методы `CacheDriver`: `set`, `get`, `setMeta`, `getMeta`, `exists`, `isExpired`, `isValid`, `purge`, `purgeBase`, `purgeExpired`, `purgeExpiredBase`, `spawn`.

```php
$cache = new Manager('product:5');
$cache->set($productData, ttl: 1800);

if ($cache->isValid()) {
    return $cache->get();
}

$cache->purge(false); // сбросить кэш в памяти, данные оставить
$cache->purge();      // удалить и данные
```

> **Изменение семантики.** Раньше `purge(false)` означало «удалить только протухшие записи» и было синонимом `purgeExpired()`. Теперь булев параметр означает «трогать ли хранилище». Для удаления протухшего вызывайте `purgeExpired()` / `purgeExpiredBase()` по имени.

Метод `make($newKey, $override)` на экземпляре возвращает новый `Manager` с тем же драйвером, но новым ключом (аналог `CacheDriver::spawn()`).

```php
$cache2 = $cache->make('product:6');
```
