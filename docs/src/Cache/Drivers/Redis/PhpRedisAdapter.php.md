# PhpRedisAdapter


## 1. Концепция

`PhpRedisAdapter` — реализация `RedisAdapterInterface` через PHP-расширение `phpredis` (класс `\Redis`) или `Relay` (`\Relay\Relay`). Приоритет отдаётся `\Redis` при создании соединения, если оба доступны.

```php
// Автоматическое создание (используется внутри RedisCacheDriver)
PhpRedisAdapter::connect([
    'host' => '127.0.0.1',
    'port' => 6379,
    'auth' => null,
    'db'   => 0,
]);

// Ручное создание с готовым объектом
$r = new \Redis();
$r->connect('127.0.0.1');
$adapter = new PhpRedisAdapter($r);
```

## 2. Публичные методы

### `static isAvailable(): bool`
Возвращает `true`, если доступен класс `\Redis` или `\Relay\Relay`.

### `static connect(array $cfg): static`
Создаёт соединение (предпочитает `\Redis`), применяет `auth` и `select db` при необходимости.

### `__construct(object $client)`
Принимает `\Redis` или `\Relay\Relay`. Бросает `InvalidArgumentException` для любого другого объекта.

Методы `hSet`, `hGet`, `hExists`, `del`, `scan` реализуют контракт `RedisAdapterInterface`..php
