<!-- DOCGEN:START -->
# SessionCacheDriver.php
<!-- DOCGEN:END -->

`ST_system\Cache\Drivers\SessionCacheDriver` — драйвер кеша поверх PHP-сессии (`$_SESSION`).
Данные живут ровно столько, сколько живёт сессия конкретного пользователя — подходит для
недолгоживущего, персонального кеша, привязанного к текущему посетителю (а не общего для всех
запросов/пользователей, как `filesystem`/`redis`/`database`).

## Выбор через конфиг

```php
CacheManager::make($key, [
    'driver' => 'session',
    'prefix' => 'st_cache', // корневой ключ в $_SESSION
    'file'   => 'data',
    'ttl'    => 0,
]);
```

- `prefix` — имя корневого ключа в `$_SESSION` (по умолчанию `'st_cache'`); под ним данные
  организованы как `$_SESSION[prefix][hash(key)][file]` и `[file.meta]`.

## Особенности

- `isAvailable()` сам стартует сессию, если она ещё не начата (`session_start()` при
  `PHP_SESSION_NONE`), и возвращает `false`, если сессии выключены (`PHP_SESSION_DISABLED`).
- Запись меты (`writeMeta()`) **проверяет TTL против `session.gc_maxlifetime`**: если
  запрошенный TTL превышает `session.gc_maxlifetime` из php.ini, бросается
  `\RuntimeException` — потому что данные сессии всё равно будут удалены сборщиком мусора
  сессий раньше, чем истечёт TTL записи, и молчаливое несоответствие было бы опаснее явной
  ошибки. TTL `-1` (бессрочно) от этой проверки освобождён.
- `purgeStorage()` / `purgeBaseStorage()` — просто `unset()` соответствующего среза
  `$_SESSION`.
- `purgeExpiredStorage()` / `purgeExpiredBaseStorage()` — проверяют `expires_in` в мете
  и вычищают только просроченные бакеты (`hash(key)`), оставляя остальные пользовательские
  записи в сессии нетронутыми.
