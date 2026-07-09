# CacheDriver


## 1. Концепция

`CacheDriver` — абстрактный базовый класс для всех драйверов кэша. Он реализует полный публичный API кэширования (`set`, `get`, `setMeta`, `getMeta`, `exists`, `isExpired`, `isValid`, `purge`, `purgeBase`) и делегирует хранение данных транспортному слою через семь абстрактных `protected` методов.

**Ключевые идеи:**

- **Ключ кэша** — произвольное значение (`$key`); внутри хранится как `md5(Main::hash($key))` в свойстве `$id`. Исходный ключ доступен через `$driver->raw_key`.
- **Именованные блобы (`$file`)** — один «слот» кэша (один `$key`) может содержать несколько независимых файлов-блобов. Параметр `$file` выбирает нужный (по умолчанию берётся из конфига как `'data'`).
- **TTL** — в секундах. `0` = использовать значение из конфига. `-1` = хранить вечно (не устаревает).
- **Метаданные** (`setMeta`/`getMeta`) хранятся рядом с каждым блобом и содержат `expires_in`, `modified_at`, `meta_modified_at`, `type`. Метаданные используются для проверки актуальности.
- **Трёхуровневый кэш в памяти** — данные, мета и флаги `exists` кэшируются в массивах `$data_cache`, `$meta_cache`, `$exists_cache`, чтобы избежать повторных обращений к хранилищу. Сбрасываются через `purge(false)`.
- **`purge($storage)`** — булев параметр разделяет «забыть» и «удалить»: `purge(false)` чистит только память, `purge(true)` (по умолчанию) — ещё и хранилище.
- **`spawn()`** — клонирует драйвер с новым ключом (и опционально новым `$file`/`$ttl`), не создавая новое соединение.
- Трейты `HasConfig` и `HasAttributes` подключаются в каждый конкретный драйвер; конфиг применяется через `Rule::scope`.

```php
// Базовое использование через конкретный драйвер
$cache = new FileSystemCacheDriver('user:42');
$cache->set(['name' => 'Иван'], ttl: 3600);

$data = $cache->get(); // ['name' => 'Иван'] или null если устарел/не существует

// Именованные блобы
$cache->set($thumbnail, file: 'thumb');
$cache->get(file: 'thumb');

// Вечное хранение
$cache->set($data, ttl: -1);

// Клонирование с новым ключом
$cache2 = $cache->spawn('user:43');
```

## 2. Абстрактные методы (для реализации драйвера)

| Метод | Назначение |
|---|---|
| `protected __init(array $config): void` | Инициализация соединения/ресурсов при создании |
| `protected __rebind(array $override): void` | Обновление ресурсов при `spawn()` (опционально) |
| `public isAvailable(): bool` | Проверка доступности хранилища |
| `protected writeBlob(string $file, string $payload): void` | Запись сериализованных данных |
| `protected readBlob(string $file): ?string` | Чтение сырых данных |
| `protected writeMeta(string $file, array $meta): void` | Запись метаданных |
| `protected readMeta(string $file): ?array` | Чтение метаданных |
| `protected blobExists(string $file): bool` | Проверка существования блоба |
| `protected purgeStorage(): void` | Удаление данных одного ключа |
| `protected purgeBaseStorage(): void` | Удаление всех данных всех ключей |

## 3. Публичные методы

### `__construct($key, array $config = []): void`
Создаёт экземпляр драйвера. `$key` — произвольный идентификатор кэша (строка, массив, число). `$config` переопределяет параметры драйвера.

```php
$cache = new FileSystemCacheDriver('page:home', ['ttl' => 600, 'dir' => '/tmp/cache']);
```

---

### `spawn($key, array $override = []): static`
Клонирует драйвер с новым ключом без повторного создания соединения. Опциональный `$override` может содержать `file`, `ttl`, а также специфичные для драйвера ключи.

```php
$base  = new RedisCacheDriver('template:header');
$other = $base->spawn('template:footer');
```

---

### `set($data, int $ttl = 0, string $file = '', array $meta = []): void`
Сохраняет данные. `$data` может быть любого типа: массив, объект, строка, число, булево. Тип сохраняется в метаданных и восстанавливается при `get()`.

`$meta` — дополнительные поля метаданных, которые запишутся **тем же** обращением к хранилищу, что и служебный ключ `type`. Отдельный `setMeta()` после `set()` не нужен: это была бы вторая запись на диск.

```php
$cache->set(['items' => [1,2,3]], ttl: 300);
$cache->set('html fragment', ttl: -1, file: 'html'); // вечное хранение

// Штамп актуальности рядом со значением, одной записью
$cache->set($minified, ttl: -1, meta: ['stamp' => $source->mtime]);
```

Ключ `type` всегда выигрывает у одноимённого поля в `$meta`.

---

### `get(string $file = ''): mixed`
Возвращает данные или `null`, если кэш не существует или истёк срок.

```php
$data = $cache->get(); // null или ранее сохранённое значение
```

---

### `setMeta(array $data, int $ttl = 0, bool $append = true, string $file = ''): void`
Обновляет метаданные блоба. При `$append = true` мержит с существующими. Поле `expires_in` вычисляется автоматически из `$ttl`.

```php
$cache->setMeta(['version' => 2], ttl: 3600);
```

---

### `getMeta(string $file = ''): array`
Возвращает метаданные блоба. Читается из хранилища один раз и запоминается в `$meta_cache` на всю жизнь драйвера.

Мемоизация **безусловна** и не зависит от `expires_in`: «свежесть записи в памяти» и «истёк ли срок жизни кэш-записи» — разные вопросы. Второй решают `isExpired()` / `isValid()`, которые сравнивают `expires_in` с текущим временем при каждом вызове, читая уже закэшированный массив.

Чтобы перечитать метаданные с диска (например, если их обновил другой процесс), вызовите `purge(false)`.

```php
$meta = $cache->getMeta();
// ['expires_in' => 1700000000, 'modified_at' => ..., 'type' => 'array']
```

---

### `exists(string $file = ''): bool`
Проверяет физическое наличие блоба в хранилище.

---

### `isExpired(string $key = 'expires_in', string $file = ''): bool`
Проверяет, истёк ли указанный временно́й ключ в метаданных. `$key = 'expires_in'` — стандартная проверка TTL.

---

### `isValid(string $key = 'expires_in', string $file = ''): bool`
Синтетическая проверка: `!isExpired() && exists()`. Используется внутри `get()`.

---

### `purge(bool $storage = true): void`
Сбрасывает внутренние кэши в памяти (`$data_cache`, `$meta_cache`, `$exists_cache`, `$attribute_cache`). При `$storage = true` дополнительно удаляет данные и метаданные текущего ключа (`$this->id`) из хранилища.

```php
$cache->purge();       // забыть и удалить
$cache->purge(false);  // только забыть — данные на диске остаются
```

`purge(false)` — то, что нужно долгоживущему процессу (демону), чтобы увидеть изменения, сделанные другим процессом, не разрушая при этом кэш.

---

### `purgeBase(bool $storage = true): void`
То же, но для всего базового каталога/префикса, а не только текущего ключа.

```php
$cache->purgeBase(); // очищает весь namespace кэша
```

---

### `purgeExpired(): void` / `purgeExpiredBase(): void`
Сбрасывают память и удаляют из хранилища только записи с истёкшим `expires_in` — для текущего ключа и для всего префикса соответственно.

```php
$cache->purgeExpiredBase(); // сборка мусора по расписанию
```
