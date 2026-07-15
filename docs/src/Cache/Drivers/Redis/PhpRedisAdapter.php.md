<!-- DOCGEN:START -->
# PhpRedisAdapter.php
<!-- DOCGEN:END -->

`class PhpRedisAdapter implements RedisAdapterInterface` (`ST_system\Cache\Drivers\Redis`) — адаптер для расширений **`phpredis`** (класс `\Redis`) и **`Relay`** (класс `\Relay\Relay`, drop-in совместимая с phpredis замена с локальным in-memory кешем на стороне клиента). `isAvailable()` возвращает `true`, если доступно хотя бы одно из двух: `class_exists(\Redis::class) || class_exists(\Relay\Relay::class)`.

## Подключение

```php
[
    'host' => '127.0.0.1',
    'port' => 6379,
    'auth' => 'secret',   // необязателен
    'db'   => 0,          // необязателен, номер логической БД Redis
]
```

`connect()` предпочитает `\Redis` (phpredis), если класс существует, иначе использует `\Relay\Relay`. В обоих случаях: `connect($host, $port)`, затем (если задан) `auth($cfg['auth'])`, затем (если `db !== 0`) `select((int)$cfg['db'])`. Конструктор адаптера принимает `object $client` и сам проверяет во время выполнения, что это экземпляр `\Redis` либо `\Relay\Relay` — иначе `\InvalidArgumentException`, то есть адаптер можно создать напрямую с уже готовым внешним клиентом, минуя `connect()`.

## Особенности реализации

- `hGet()` приводит `null` (ключ/поле не найдено) к `false` — единообразно с остальными адаптерами кеша.
- `del()` и `hSet()`/`hExists()` — тонкие обёртки один-в-один над одноимёнными методами клиента (сигнатуры `\Redis` и `\Relay\Relay` совместимы).
- `scan()` нормализует `$cursor` (`null`/`false` → `0`) перед вызовом клиентского `scan($cursor, $pattern, $count)` — сам метод клиента обновляет `$cursor` по ссылке; когда сервер сигнализирует об окончании обхода, phpredis/Relay сами выставляют курсор в `0`. Возвращает `false`, если клиент вернул `false` (ошибка/нет совпадений на этом шаге).
- Поддерживает и классический синхронный `\Redis`, и `\Relay\Relay` — по сути одна и та же реализация адаптера обслуживает оба клиента благодаря почти идентичному API.
