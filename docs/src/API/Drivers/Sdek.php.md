<!-- DOCGEN:START -->
# Sdek.php
<!-- DOCGEN:END -->

`final class Sdek extends IntegrationDriver` (`ST_system\API\Drivers\Sdek`) — драйвер для API службы доставки СДЭК (`https://api.cdek.ru/v2/`, актуальная REST-версия "Интеграция 2.0"). Наследует [`IntegrationDriver`](../IntegrationDriver.php.md) — общий HTTP/кеш/событийный пайплайн описан там, здесь — только специфика СДЭК: OAuth2-авторизация, конкретные методы и их схемы.

Конфиг по умолчанию: `endpoint = 'https://api.cdek.ru/v2/'`, `cache.use = true` (включён instance-level кеш — токен авторизации кешируется между запросами).

## Создание инстанса и авторизация

```php
$sdek = Sdek::create([
    'client_id'     => '...',
    'client_secret' => '...',
]);
```

При создании драйвер сразу же вызывает `oauth/token` (см. ниже) с переданными параметрами и сохраняет из ответа `access_token`, `token_type`, `jti` в `$this->SETTINGS`. Дальше на событии `before_curl_init` заголовок `Authorization: Bearer {access_token}` автоматически проставляется на **все** последующие запросы, если токен получен. На событии `save_cache` TTL кеша для метода `oauth/token` переопределяется значением `expires_in` из самого ответа (если оно есть) — то есть токен кешируется ровно на тот срок, который вернул сервер СДЭК, а не на статичный `cache_ttl` из объявления метода.

На событии `prepare_response`, если `http_code` вне диапазона `200..299`, тело ответа кладётся в `$raw_data['error']` — то есть неуспешные ответы СДЭК (ошибки валидации, 4xx/5xx) превращаются в `curl_error`/исключение стандартного пайплайна `IntegrationDriver`, а не молча возвращаются как "успешный" JSON.

## Зарегистрированные методы

- **`oauth/token`** (`POST`) — получение access-токена по client credentials.
  ```php
  $sdek->call('oauth/token', ['client_id' => '...', 'client_secret' => '...']);
  ```
  Параметры: `grant_type` (по умолчанию `client_credentials`, допустимо только это значение), `client_id` (обязателен), `client_secret` (обязателен). `cache_ttl` объявления — `3600` секунд (фактический TTL переопределяется через `expires_in`, см. выше).

- **`location/suggest/cities`** — подсказки городов по названию.
  ```php
  $sdek->call('location/suggest/cities', ['name' => 'Москва']);
  ```
  Параметры: `name` (обязателен), `country_code` (опционально). Кеш — 3600 секунд.

- **`location/cities`** — список городов СДЭК с фильтрацией и пагинацией.
  ```php
  $sdek->call('location/cities', ['country_code' => 'RU', 'page' => 0]);
  ```
  Параметры: `lang`, `country_code`, `region_code` (без `city_code` — он исключён из этого набора), `page`, `size`. Если передан `page`, но не передан `size` — `size` автоматически подставляется равным `1000` (`on_prepare`). Кеш — 3600 секунд.

- **`deliverypoints`** — список пунктов выдачи заказов (ПВЗ/постаматов).
  ```php
  $sdek->call('deliverypoints', ['type' => 'PVZ', 'city_code' => 44]);
  ```
  Параметры: `type` (по умолчанию `ALL`, допустимо `PVZ`/`ALL`/`POSTAMAT`), плюс полный набор `default_params` (`lang`, `country_code`, `region_code`, `city_code`) и пагинация (`page`/`size`, с той же авто-подстановкой `size = 1000`). Кеш — 3600 секунд.

- **`calculator/tariff`** (`POST`, `content_type: application/json`) — расчёт стоимости и сроков доставки по конкретному тарифу.
  ```php
  $sdek->call('calculator/tariff', [
      'tariff_code'   => 136,
      'type'          => 1,
      'from_location' => ['code' => 44],
      'to_location'   => ['code' => 137],
      'packages'      => [['weight' => 1000, 'length' => 10, 'width' => 10, 'height' => 10]],
  ]);
  ```
  Параметры: `tariff_code` (обязателен), `type` (по умолчанию `1`, допустимо `1`/`2`), `from_location`/`to_location` — валидируются как `CalculatorLocationDto` (`code`, `postal_code`, `country_code`, `city`, `address`, `contragent_type` — по умолчанию `INDIVIDUAL`, допустимо `LEGAL_ENTITY`/`INDIVIDUAL`, `longitude`, `latitude`), `packages` — массив объектов `CalcPackageRequestDto` (`weight` обязателен, `length`/`width`/`height` опциональны). Без кеша (`cache_ttl` не задан → `0`).

- **`orders`** — получение информации о заказе по номеру СДЭК.
  ```php
  $sdek->call('orders', ['cdek_number' => '1234567890']);
  ```
  Параметр: `cdek_number` (обязателен). Без кеша.

- **`calculator/alltariffs`** — расчёт по всем доступным тарифам сразу. Без параметров валидации (принимает произвольное тело — валидация не объявлена, значит `params` пуст). Кеш — 3600 секунд.

```php
$sdek->callMany([
    ['location/suggest/cities', ['name' => 'Санкт-Петербург']],
    ['deliverypoints', ['city_code' => 137]],
]); // конкурентный батч, авторизационный заголовок проставляется на оба запроса одинаково
```
