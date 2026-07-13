# IntegrationDriver.php

Абстрактная база всех API-драйверов (`src/API/Drivers/*`). Даёт единый механизм:
регистрация методов API, валидация параметров, событийный конвейер запрос → ответ,
кеширование ответов и **транспорт поверх [`WebClient`](../HTTP/WebClient.php.md)**
(`curl_multi`). Раньше драйвер сам собирал `curl_init`/`curl_exec`; теперь весь HTTP
идёт через `WebClient`, но легаси-форма ответа и порядок событий сохранены — конкретные
драйверы менять не нужно.

## Конфигурация (`getDefaultConfig`)

| Ключ | По умолчанию | Назначение |
|------|--------------|------------|
| `endpoint` | `''` | Базовый URL API. |
| `timeout` | `30.0` | Таймаут запроса, сек (0 — без ограничения). |
| `connect_timeout` | `10.0` | Таймаут соединения, сек. |
| `verify` | `true` | SSL-проверка транспорта (как прежний `SSL_VERIFYPEER`). Для `http://`-эндпоинтов задайте `false` (WebClient c `verify=true` их отвергает). |
| `requeue` | `0` | Повторы неудачных запросов: `0` — выключены, `<0` — без лимита, `>0` — макс. на запрос. |
| `batch` | `10` | Размер окна параллельных запросов для `callMany()`. |
| `delay` | `0` | Пауза между окнами `callMany()`, мс. |
| `cache.use` | `false` | Включить кеш ответов драйвера. |
| `cache.dir` | `''` | Каталог кеша. |
| `cache.driver` | `filesystem` | Драйвер кеша (`Cache\Manager`). |

Значения `timeout`/`connect_timeout`/`verify`/`requeue` можно переопределить **на уровне
метода** в его конфиге (см. `registerMethod`).

## Регистрация методов

```php
protected function __init(): void {
    $this->registerMethodsMap([
        'orders/create' => [
            'method'       => 'POST',
            'content_type' => 'application/json',
            'params'       => [ 'Amount' => 'required|numeric', /* Rule-схема */ ],
            'cache_ttl'    => 0,
        ],
        'orders/{id}' => ['method' => 'GET'],       // {id} — обязательный параметр пути
        'authorize'   => fn(array $p) => /* ... */,  // метод-замыкание: своя логика вместо HTTP
    ]);
}
```

Ключи конфига метода: `endpoint`, `headers` (map), `content_type`
(`application/x-www-form-urlencoded` | `application/json`), `method` (`GET`|`POST`),
`params` (компилируется в `Rule::object`), `on_prepare` (callable, правит `$params`),
`cache_ttl` (`>0` или `-1` — кешировать), `meta`, `timeout`, `connect_timeout`, `verify`,
`requeue`. Плейсхолдеры `{param}` в имени метода становятся обязательными и подставляются
из параметров вызова.

`registerMethod($method, \Closure $fn)` — **метод-замыкание**: `call()` просто вызывает
`$fn($params)` и возвращает результат (свой транспорт/логика, минуя HTTP-конвейер).

## Вызовы

### `call(string $method, array $params = [])`

Один запрос. Порядок стадий (события — [`HasEvents`](../Traits/HasEvents.php.md)):

1. Резолв метода (в т.ч. по шаблону `{param}`), валидация `params` + `on_prepare`.
2. `before_call($method, $params)`
3. `call($method, &$params)` — правка параметров (инъекция токена и т.п.).
4. Подстановка `{param}`, `build_url` → `build_url(&$url, $endpoint, $method, &$params)`.
5. `before_curl_init(&$url, $method, &$params, &$config)` — правка URL/параметров/конфига
   (метод, `content_type`, заголовки). Мутации по ссылке видят и запрос, и ключ кеша, и
   события ответа.
6. Чтение кеша (если `cache_ttl`) → иначе транспорт (`WebClient`).
7. `prepare_response($method, $params, &$raw)` — правка «сырого» ответа.
8. Если `raw['error']` — `curl_error($method, $params, $raw)`; нет слушателя → исключение,
   иначе `call()` возвращает `false`.
9. `decode_response($method, $params, &$raw)` — нет слушателя → `json_decode`; есть — ответ
   берётся как есть (`raw['response']`).
10. `response($method, $params, $response)`; затем `save_cache($method, $params, $response, &$meta)`.

**Легаси-форма `$raw`**: `['response', 'error', 'http_code', 'effective_url']` — на ней
работают все слушатели `prepare_response`/`curl_error`/`decode_response`. Семантика `fire()`:
возвращает `false`, только если **нет** слушателей (отсюда паттерн «`=== false` → поведение
по умолчанию»).

### `callMany(array $calls, array $opts = [])`

Набор произвольных вызовов **параллельно**, одной очередью `WebClient::group()` (окна
`batch` с паузой `delay`, повторы по `requeue`). Каждый запрос проходит те же события, что и
`call()` (кроме response-level кеша). Результаты — **в порядке входного массива**.

Формат элемента `$calls`: `'method'` | `['method', $params]` | `['method' => …, 'params' => …]`.

```php
$driver->callMany([
    ['orders/1', []],
    ['method' => 'orders/create', 'params' => ['Amount' => 10]],
    'ping',
], ['batch' => 5, 'delay' => 200]);
```

Методы-замыкания в `callMany()` выполняются на месте (не параллелятся).

## События (reserved)

`__construct`, `before_curl_init`, `encode_request`, `before_call`, `call`, `build_url`,
`prepare_response`, `curl_error`, `decode_response`, `response`, `save_cache`.

- `encode_request(&$url, $method, &$params, $config)` — своё кодирование тела: если слушатель
  есть (fire ≠ false), тело берётся как оставил слушатель (массив → WebClient кодирует по
  `Content-Type`; строка вида `a=b&c=d` разворачивается обратно в массив). `$method` —
  имя метода API (не HTTP-глагол).
- `save_cache(..., &$meta)` — динамический TTL: слушатель ставит `$meta['ttl']`.

## Кеш и повторы

- **Кеш ответов** (`cache_ttl` + `save_cache` + `$this->cache`) кеширует декодированный ответ
  (`json_encode($raw)` по ключу `[$url, $params]`). Остаётся на стороне драйвера: заголовочный
  кеш `WebClient` не умеет выражать TTL из тела ответа (`save_cache`).
- **Повторы** (`requeue`) — транзиентные сбои (`curl errno` / HTTP ≥ 500) переотправляются
  очередью `WebClient` до `requeue` раз, затем ошибка уходит в `curl_error`/исключение.

## Защищённые помощники для наследников

- `request(string $url, string $method, array $params, array $config): array` — единый
  транспорт (один запрос → легаси-`$raw`). Для ad-hoc запросов вне карты методов (напр. OAuth).
- `methodConfig(string $method = '')` — конфиг метода (или вся карта). Используйте вместо
  прямого доступа к приватному `methods_map`.
- `build_url()`, `cache()`, `getEndpoint()`, `registerMethod(s)`, `unregisterMethod(s)`.
