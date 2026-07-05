# RedisCacheDriver


## 1. Концепция

`RedisCacheDriver` — кэш-драйвер на основе Redis. Данные хранятся в Redis-хэшах: каждый ключ кэша (`$id`) образует один хэш-ключ вида `<prefix><id>`, в полях которого хранятся блобы и `.meta`-записи.

**Особенности:**

- Поддерживает два PHP-клиента: `phpredis` (расширение `\Redis` или `\Relay\Relay`) через `PhpRedisAdapter`, и `predis/predis` через `PredisAdapter`. Адаптер выбирается автоматически.
- Соединения пулируются `static $pool` по хэшу параметров подключения — одно соединение на уникальный сервер.
- Готовое соединение можно передать напрямую через `$config['connection']` (экземпляр `RedisAdapterInterface`).
- `isAvailable()` возвращает `false`, если ни один адаптер недоступен или соединение не установлено.
- `purgeBase()` итерирует по ключам Redis с паттерном `<prefix>*` через `scan` (без блокировки сервера).

```php
$cache = new RedisCacheDriver('session:token_abc', [
    'host'   => '127.0.0.1',
    'port'   => 6379,
    'prefix' => 'myapp:cache:',
    'ttl'    => 1800,
]);
$cache->set(['user_id' => 5]);
$data = $cache->get();
```

```php
// Передача готового соединения
$redis = new \Redis();
$redis->connect('127.0.0.1');
$adapter = new PhpRedisAdapter($redis);

$cache = new RedisCacheDriver('key', ['connection' => $adapter]);
```

## 2. Конфигурация

| Ключ | По умолчанию | Описание |
|---|---|---|
| `host` | `null` | Хост Redis-сервера |
| `port` | `6379` | Порт |
| `auth` | `null` | Пароль аутентификации |
| `db` | `0` | Номер базы данных Redis |
| `prefix` | `'cache:'` | Префикс всех ключей |
| `file` | `'data'` | Имя блоба по умолчанию |
| `ttl` | `3600` | TTL в секундах |
| `connection` | `null` | Готовый экземпляр `RedisAdapterInterface` |

## 3. Публичные методы

### `isAvailable(): bool`

Возвращает `true`, если соединение с Redis установлено.

Остальные публичные методы наследуются от `CacheDriver`: `set`, `get`, `setMeta`, `getMeta`, `exists`, `isExpired`, `isValid`, `purge`, `purgeBase`, `spawn`..php
