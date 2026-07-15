<!-- DOCGEN:START -->
# CloudPayments.php
<!-- DOCGEN:END -->

`final class CloudPayments extends IntegrationDriver` (`ST_system\API\Drivers\Acquiring\CloudPayments`) — интеграция с платёжным шлюзом [CloudPayments](https://cloudpayments.ru/). Один из платёжных драйверов проекта (наравне с `Robokassa`, `TBank`), построен поверх декларативной карты методов `IntegrationDriver`: конкретные HTTP-эндпоинты API описаны через `registerMethodsMap()` в `__init()`, а сам класс лишь донастраивает общий пайплайн (`WebClient` + `Rule` + кеш) под особенности CloudPayments — Basic-авторизацию и формат ошибок.

## Конструктор

```php
CloudPayments::create([
    'public_id'  => 'pk_...',
    'api_secret' => '...',
]);
```

`public_id` и `api_secret` — обязательные строки (`Rule::object(...)->throwable()`), сохраняются в приватном `$SETTINGS` и используются на каждом запросе.

## Авторизация и обработка ошибок

- `before_curl_init` принудительно ставит `method => POST` и заголовок `Authorization: Basic base64(public_id:api_secret)` — CloudPayments аутентифицирует все вызовы через Basic Auth, поэтому это не настраивается на уровне отдельного метода.
- `prepare_response` — если `http_code` вне диапазона 200–299, весь сырой ответ кладётся в `$raw_data['error']` (сообщение об ошибке формирует `IntegrationDriver::processResponse()` дальше по пайплайну).

## Зарегистрированные методы (`registerMethodsMap`)

- **`test`** — без параметров, пинг API (проверка, что `public_id`/`api_secret` валидны).
- **`payments/find`** — поиск платежа: `InvoiceId` (`required|string`).
- **`orders/create`** — выставление счёта/заказа:
  - `Amount` — обязателен, должен быть положительным числом; автоматически приводится к строке с двумя знаками после запятой (`number_format`);
  - `Currency` — по умолчанию `RUB`, допустимо `RUB`/`EUR`/`USD`;
  - `Description` — обязательная строка;
  - `InvoiceId`, `AccountId` — опциональные строки;
  - `SuccessRedirectUrl` — опциональный URL.
- **`orders/cancel`** — отмена заказа: `Id` (`required|string`).
- **`site/notifications/{Type}/update`** — настройка вебхук-уведомлений сайта. `{Type}` — плейсхолдер пути, ограничен списком `Pay`, `Fail`, `Confirm`, `Refund`, `Recurrent`, `Cancel`. Остальные параметры: `Address` (URL получателя), `IsEnabled` (bool), `HttpMethod` (`GET`/`POST`, по умолчанию `GET`), `Encoding` (`UTF8`/`Windows1251`, по умолчанию `UTF8`), `Format` (`CloudPayments`/`QIWI`/`RT` или `null`). Хук `on_prepare` требует, чтобы при `IsEnabled == true` обязательно был передан `Address` — иначе бросает исключение до отправки запроса.

## Примеры использования

```php
$driver = CloudPayments::create([
    'public_id'  => getenv('CP_PUBLIC_ID'),
    'api_secret' => getenv('CP_API_SECRET'),
]);

// создать заказ
$order = $driver->call('orders/create', [
    'Amount'      => 1500,
    'Currency'    => 'RUB',
    'Description' => 'Оплата брони №42',
    'InvoiceId'   => 'INV-42',
]);

// найти платёж по InvoiceId
$payment = $driver->call('payments/find', ['InvoiceId' => 'INV-42']);

// отменить заказ
$driver->call('orders/cancel', ['Id' => $order['Model']['Id']]);

// включить уведомления об оплате
$driver->call('site/notifications/{Type}/update', [
    'Type'       => 'Pay',
    'Address'    => 'https://example.com/webhooks/cloudpayments/pay',
    'IsEnabled'  => true,
    'HttpMethod' => 'POST',
]);
```
