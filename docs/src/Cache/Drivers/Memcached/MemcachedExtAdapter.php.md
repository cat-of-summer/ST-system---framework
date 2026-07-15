<!-- DOCGEN:START -->
# MemcachedExtAdapter.php
<!-- DOCGEN:END -->

`class MemcachedExtAdapter implements MemcachedAdapterInterface` (`ST_system\Cache\Drivers\Memcached`) — адаптер для современного PECL-расширения **`memcached`** (обёртка над `libmemcached`, класс `\Memcached`). `isAvailable()` проверяет `class_exists(\Memcached::class)`.

## Подключение

```php
[
    'persistent_id' => 'app-pool',   // необязателен: включает persistent-соединение
    'servers' => [
        ['host' => '10.0.0.1', 'port' => 11211, 'weight' => 0],
        // либо индексированной формой: ['10.0.0.2', 11211, 10]
    ],
    // либо один сервер вместо 'servers':
    'host' => '127.0.0.1',
    'port' => 11211,
    'auth' => 'user:pass',           // необязателен: SASL-аутентификация
]
```

Если задан `persistent_id`, создаётся `new \Memcached($persistent_id)` — расширение само переиспользует один и тот же пул соединений между запросами по этому идентификатору. Список серверов добавляется (`addServers()`) только если у клиента ещё нет ни одного сервера (`$m->getServerList()` пуст) — при persistent-соединении сервер(-а) уже могли быть добавлены в предыдущем запросе, повторное добавление создало бы дубликаты. Если задан `auth` в формате `"user:pass"`, включается бинарный протокол (`OPT_BINARY_PROTOCOL`) и вызывается `setSaslAuthData()` (расширение должно быть собрано с поддержкой SASL). Если ни `servers`, ни `host` не дали ни одного валидного сервера — `\RuntimeException`.

## Особенности реализации

- `get()` возвращает `false` **только** если реальный код результата — не `RES_SUCCESS` (`getResultCode()`), то есть отличает "значение действительно `false`" от "ключа нет" не требуется вызывающему коду отдельно — оба случая приводятся к одному `false`.
- `delete()` использует `deleteMulti()` для массива ключей (один round-trip) и обычный `delete()` для одиночного.
- `exists()` реализован через `get()` + проверку `getResultCode() === RES_SUCCESS` — отдельного "проверить не читая" метода в `\Memcached` нет.
- Поддерживает persistent-соединения и SASL-аутентификацию — то, чего нет в устаревшем `MemcacheExtAdapter`.
