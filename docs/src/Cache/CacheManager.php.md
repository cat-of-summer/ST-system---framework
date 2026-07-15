<!-- DOCGEN:START -->
# CacheManager.php
<!-- DOCGEN:END -->

`ST_system\Cache\CacheManager` — центральный, единый вход во всю систему кеширования
фреймворка. Это `final class`, фасад и фабрика над драйверами (`ST_system\Cache\CacheDriver`
и его наследниками в `Cache\Drivers\*`): выбирает нужный драйвер по имени, конфигурирует его и
делегирует ему всю фактическую работу. Используется повсеместно: `View`, `Assets`,
`API\IntegrationDriver`, `HTTP\WebClient`, `Storage\File`, `Access`, парсеры и т.д. — все они
получают кеш через `CacheManager::make(...)`, а не создают драйверы напрямую.

## Конфигурация

`getDefaultConfig()` задаёт:

- `default.dir` — базовая директория кеша (`'~/cache/'`, `~` резолвится в `DOCUMENT_ROOT`/
  `COMPOSER_ROOT`, см. `Main::preparePath()`).
- `default.ttl` — TTL по умолчанию (`3600` секунд), если конкретный вызов/драйвер его не
  переопределяет.
- `drivers.default` — имя драйвера по умолчанию (`'filesystem'`).
- `drivers.available` — карта имя → класс: `filesystem`, `redis`, `memcached`, `database`,
  `session`.

`CacheManager::setConfig([...])` умеет одним вызовом настраивать и сам менеджер, и
конкретные драйверы — ключи `drivers.<имя>.<параметр>` (кроме служебных `drivers.default` и
`drivers.available`) автоматически уводятся в `<КлассДрайвера>::setConfig([...])`, а не
остаются в конфиге `CacheManager`. Например:

```php
CacheManager::setConfig([
    'default.ttl'        => 7200,
    'drivers.default'    => 'redis',
    'drivers.redis.host' => '127.0.0.1',
    'drivers.redis.port' => 6379,
]);
```

здесь `default.ttl` и `drivers.default` попадут в конфиг самого `CacheManager`, а
`host`/`port` — в конфиг `RedisCacheDriver` (класс резолвится через
`drivers.available.redis`).

## Создание и автофолбэк драйвера

```php
$cache = CacheManager::make($key, [
    'driver' => 'redis',   // необязательно; по умолчанию drivers.default
    'ttl'    => 600,
    'file'   => 'data',
    // + специфичные для драйвера ключи (host, port, dir, prefix, ...)
]);
```

`$key` — произвольное значение (строка, массив, вложенные структуры), которое будет захэшировано
через `Main::hash()`; удобно передавать составной ключ, например
`[__CLASS__, $this->name, $this->props]`, чтобы получить разные записи кеша для разных
параметров без ручной конкатенации строк.

Конструктор резолвит `driver` из `$config['driver']` (или `drivers.default`, если не задан),
создаёт экземпляр этого драйвера и сразу проверяет `isAvailable()`. **Если запрошенный драйвер
недоступен** (например, Redis/Memcached не поднялся, БД не сконфигурирована) **и это не
совпадает с драйвером по умолчанию — менеджер автоматически пересоздаёт кеш на драйвере по
умолчанию** (обычно `filesystem`). Это молчаливый фолбэк: код, вызывающий `CacheManager`,
не должен сам проверять доступность бэкенда.

Если в `$key` передан уже готовый экземпляр `CacheDriver` — конструктор просто оборачивает
его как есть, без повторного резолва/фолбэка (так работают внутренние `make()`/`spawn()`).

## Основной API

Инстанс `CacheManager` прозрачно делегирует вызовы внутреннему драйверу через `__call`:

```php
$cache = CacheManager::make($key, ['driver' => 'filesystem', 'ttl' => 600]);

$cache->set($data);                 // записать (file/ttl по умолчанию из конфига)
$cache->set($data, 300, 'variant'); // записать в другой "файл"-слот с ttl=300
$data  = $cache->get();             // null, если нет валидной записи
$meta  = $cache->getMeta();
$cache->setMeta(['note' => 'x']);
$cache->exists();
$cache->isValid();
$cache->isExpired();
$cache->purge();          // удалить текущий ключ
$cache->purgeBase();      // удалить ВСЁ пространство этого драйвера
$cache->purgeExpired();
$cache->purgeExpiredBase();
```

### remember() — получить или вычислить и сохранить

```php
$html = CacheManager::make($key, ['ttl' => -1])
    ->remember(fn() => expensiveRender(), -1, '', $depsStamp);
```

`remember(callable $cb, int $ttl = 0, string $file = '', $stamp = null)`:
1. если `$stamp === null`, либо `$stamp` совпадает с уже сохранённым `getMeta($file)['stamp']` —
   пробует отдать закешированное значение (`get($file)`); при непустом результате возвращает его
   сразу, не вызывая `$cb`;
2. иначе вызывает `$cb()`, сохраняет результат через `set($data, $ttl, $file, ...)` и, если
   `$stamp` передан, кладёт его в мету как `meta['stamp']`.

`$stamp` — это внешний маркер версии производных данных (например, mtime исходного файла или
хэш набора зависимостей), а не `modified_at` самой записи кеша: несовпадение `stamp` — сигнал,
что источник изменился и кеш нужно пересчитать, даже если TTL ещё не истёк. Так, например,
`Assets::setManifest()` кеширует манифест на `-1` (бессрочно) и полагается только на `stamp`
(mtime favicon-файла) для инвалидации, а `View` кладёт в мету `deps`/`stamp` от хэша зависимостей
компонента.

### Инстанс-метод make() — смена ключа с переиспользованием соединения

```php
$sibling = $cache->make($otherKey, ['ttl' => 60]); // тот же класс драйвера/соединение,
                                                     // новый ключ и/или file/ttl
```

В отличие от статического `CacheManager::make($key, $config)` (создающего новый драйвер с нуля),
вызванный на инстансе `make()` использует `CacheDriver::spawn()` — клонирует уже поднятый драйвер
(с уже открытым соединением к Redis/БД/Memcached) и просто переключает его на новый ключ. Если
`$key` не передан — переиспользуется текущий `raw_key`. Это то, что использует
`API\IntegrationDriver` и `HTTP\WebClient`, чтобы не переоткрывать соединение на каждый под-ключ.

### Статические ярлыки

Есть также чисто статические ярлыки без явного создания переменной:

```php
CacheManager::set($key, $data, 'file', 600);
$data = CacheManager::get($key, 'file');
CacheManager::setMeta($key, ['a' => 1]);
$meta = CacheManager::getMeta($key);
$data = CacheManager::remember($key, fn() => compute(), 'file', 600);
CacheManager::purgeBase();          // ключ не нужен — работает на драйвере по умолчанию
CacheManager::purgeExpiredBase();
```

Каждый такой вызов под капотом создаёт временный `CacheManager::make($key, [...])` и делегирует
ему один вызов — удобно для одноразовых обращений, когда не нужно держать переменную кеша.

### Доступ к атрибутам драйвера

`$cache->ttl`, `$cache->file`, `$cache->dir`, `$cache->raw_key` и т.п. прозрачно проксируются
в атрибуты (`__get`/`__isset`) активного драйвера — удобно прочитать, с каким TTL/директорией/
исходным ключом было создано соединение, не заглядывая внутрь драйвера напрямую.
