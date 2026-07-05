# IntegrationDriver

## 1. Концепция

`IntegrationDriver` — абстрактный базовый класс для всех API-интеграций библиотеки (SDEK, Telegraph, SmsRu, Bitrix24 и т.д.). Подкласс регистрирует методы API через `registerMethod`, затем вызывает их через `call`. Весь цикл запроса насыщен событиями (`HasEvents`), что позволяет перехватывать любой этап без переопределения класса.

**Ключевые идеи:**

- **Регистрация методов** — в `__init()` подкласса вызывается `registerMethod($method, $config)`. Метод — это URL-путь вида `'v1/orders/{id}'`. Параметры в фигурных скобках автоматически валидируются через `Rule`.
- **Кэширование** — если `cache.ttl > 0`, на уровне драйвера и целя отдельно попадшего метода.
- **События** (зарезервированные): `__construct`, `before_curl_init`, `curl_init`, `encode_request`, `before_call`, `call`, `build_url`, `prepare_response`, `curl_error`, `decode_response`, `response`, `save_cache`.

```php
class SomeApi extends IntegrationDriver {

    protected static function getDefaultConfig(): array {
        return array_merge(parent::getDefaultConfig(), [
            'endpoint' => 'https://api.example.com',
            'api_key'  => '',
        ]);
    }

    protected function __init(): void {
        $this->registerMethod('users/list', [
            'method' => 'GET',
            'params' => ['page' => Rule::create()->int()->min(1)],
        ]);

        $this->registerMethod('users/{id}', [
            'method' => 'GET',
        ]);

        // Перехват заголовков
        $this->on('curl_init', function($curl) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer '.static::config('api_key'),
            ]);
        });
    }
}

$api = SomeApi::create();
$users = $api->call('users/list', ['page' => 1]);
$user  = $api->call('users/{id}', ['id' => 42]); // автоматически находит шаблон
```

## 2. Конфигурация

| Ключ | По умолчанию | Описание |
|---|---|---|
| `endpoint` | `''` | Базовый URL API |
| `cache.ttl` | `false` | TTL кэша (в секундах). `false` означает кэш отключён |
| `cache.driver` | `'filesystem'` | Драйвер кэша |
| `cache.dir` | `''` | Каталог кэша (filesystem) |

## 3. Публичные методы

### `static create(...$params): static`
Фабричный метод. Эквивалентен `new static(...$params)`.

---

### `call(string $method, array $params = []): mixed`
Выполняет HTTP-запрос к зарегистрированному методу. Сець событий:
1. Находит метод (в т.ч. пагины `{param}`) → `before_call`
2. Валидирует параметры через `Rule` → `call`
3. Строит URL (`build_url`) → инициализирует cURL
4. Проверяет кэш → выполняет cURL если нет в кэше
5. JSON-декодирует ответ (`decode_response`, `response`)
6. Сохраняет в кэш (`save_cache`)

```php
$result = $api->call('orders', ['status' => 'active']);
```

---

### `protected registerMethod(string $method, array|Closure $config): static`
Регистрирует запрос. Ключи `$config`:

| Ключ | Описание |
|---|---|
| `method` | `'GET'` или `'POST'` |
| `endpoint` | Переопределить базовый URL для этого метода |
| `params` | Массив равнов `Rule` для валидации |
| `content_type` | `'application/x-www-form-urlencoded'` или `'application/json'` |
| `headers` | Дополнительные HTTP-заголовки |
| `on_prepare` | Callable для изменения `$params` перед отправкой |
| `cache_ttl` | TTL кэша для этого метода |
| `meta` | Дополнительные метаданные |

---

### `protected registerMethodsMap(array $methods): static`
Регистрирует несколько методов сразу.

### `protected unregisterMethod(string $method): static`
Удаляет ранее зарегистрированный метод.

### `protected cache(): ?Cache`
Возвращает экземпляр кэш-менеджера драйвера (если кэш включён).

### `protected __init(): void`
Определяется в подклассе. Здесь нужно зарегистрировать методы и навешивать события..php
