# PredisAdapter


## 1. Концепция

`PredisAdapter` — реализация `RedisAdapterInterface` через композерный пакет `predis/predis` (`\Predis\Client`). Является запасным вариантом, если PHP-расширение `phpredis` не установлено.

```php
// Автоматическое создание
PredisAdapter::connect([
    'host' => '127.0.0.1',
    'port' => 6379,
]);

// Ручное создание
$client  = new \Predis\Client(['host' => '127.0.0.1', 'port' => 6379]);
$adapter = new PredisAdapter($client);
```

## 2. Публичные методы

### `static isAvailable(): bool`
Возвращает `true`, если класс `\Predis\Client` доступен.

### `static connect(array $cfg): static`
Создаёт `\Predis\Client` с параметрами подключения.

Методы `hSet`, `hGet`, `hExists`, `del`, `scan` реализуют контракт `RedisAdapterInterface`.
При `scan` используется `Predis SCAN` с `match`/`count` опциями; курсор обновляется по ответу `[cursor, keys]`..php
