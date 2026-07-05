# SessionCacheDriver


## 1. Концепция

`SessionCacheDriver` — кэш-драйвер, хранящий данные в суперглобальной переменной `$_SESSION`. Ключи группируются по пространству имён и идентификатору ключа: `$_SESSION[namespace][id][file]`.

**Особенности:**

- Автоматически запускает сессию при первом обращении (`session_start()`), если она ещё не активна.
- При записи метаданных проверяет, что запрошенный TTL не превышает `session.gc_maxlifetime` — иначе бросает `RuntimeException`.
- `purge()` удаляет данные конкретного ключа из сессии; `purgeBase()` очищает весь namespace.
- Данные живут ровно столько, сколько живёт сессия; TTL по умолчанию не ограничен (`0`).

```php
// Прямое использование
$cache = new SessionCacheDriver('user:42', [
    'prefix' => 'my_cache',
    'ttl'    => 300,
]);
$cache->set(['name' => 'Ivan']);
echo $cache->get()['name']; // Ivan
```

Чаще всего используется через `Manager`:

```php
Manager::setConfig(['drivers' => ['session' => ['prefix' => 'my_cache']]]);
$data = Manager::get('user:42', driver: 'session');
```

## 2. Конфигурация

| Ключ | По умолчанию | Описание |
|---|---|---|
| `file` | `'data'` | Имя файла блоба по умолчанию |
| `ttl` | `0` | TTL в секундах (0 = без ограничения, -1 = вечно) |
| `prefix` | `'st_cache'` | Ключ верхнего уровня в `$_SESSION` |

## 3. Публичные методы

### `isAvailable(): bool`

Возвращает `true`, если сессия активна. Запускает сессию автоматически, если она была в состоянии `PHP_SESSION_NONE`. Возвращает `false`, если сессии отключены (`PHP_SESSION_DISABLED`).

```php
if (!$cache->isAvailable()) {
    // сессии отключены на сервере
}
```

Остальные публичные методы наследуются от `CacheDriver`: `set`, `get`, `setMeta`, `getMeta`, `exists`, `isExpired`, `isValid`, `purge`, `purgeBase`, `spawn`.
