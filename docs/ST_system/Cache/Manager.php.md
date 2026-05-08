# Manager

> Документация сгенерирована AI-агентом на основе исходного кода.

## 1. Концепция

`Manager` — финальный класс-фасад для системы кэширования. Он не является самостоятельным хранилищем — за реальную работу отвечает один из драйверов (`FileSystemCacheDriver`, `RedisCacheDriver`, `DatabaseCacheDriver`). `Manager` выбирает нужный драйвер при создании, проверяет его доступность и при необходимости откатывается на драйвер по умолчанию.

**Ключевые идеи:**

- **Выбор драйвера** — задаётся ключом `'driver'` в `$config` при создании экземпляра. Значение может быть алиасом (`'redis'`) или полным именем класса.
- **Fallback** — если запрошенный драйвер недоступен (`isAvailable() === false`), автоматически используется `drivers.default` (по умолчанию `FileSystemCacheDriver`).
- **Статические методы-ярлыки** — `Manager::get()`, `Manager::set()`, `Manager::remember()` и другие создают экземпляр на лету; не нужно явно создавать объект.
- **`remember()`** — паттерн «получи из кэша или вычисли и сохрани».
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

### Методы экземпляра (проксируются к драйверу)

Экземпляр `Manager` поддерживает все методы `CacheDriver`: `set`, `get`, `setMeta`, `getMeta`, `exists`, `isExpired`, `isValid`, `purge`, `purgeBase`.

```php
$cache = new Manager('product:5');
$cache->set($productData, ttl: 1800);

if ($cache->isValid()) {
    return $cache->get();
}
```

Метод `make($newKey, $override)` на экземпляре возвращает новый `Manager` с тем же драйвером, но новым ключом (аналог `CacheDriver::spawn()`).

```php
$cache2 = $cache->make('product:6');
```.php
