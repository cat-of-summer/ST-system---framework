<!-- DOCGEN:START -->
# TBank.php
<!-- DOCGEN:END -->

`final class TBank extends IntegrationDriver` (`ST_system\API\Drivers\Acquiring\TBank`) — интеграция с эквайрингом Т‑Банка (бывш. Тинькофф, [Т‑Кассы](https://www.tbank.ru/kassa/)). Один из платёжных драйверов проекта (наравне с `CloudPayments`, `Robokassa`). Все запросы к API Т‑Банка подписываются токеном, вычисляемым из отсортированных значений параметров запроса; этот же алгоритм используется и для отправки запросов, и для верификации входящих webhook-уведомлений.

## Конструктор

```php
TBank::create([
    'terminal_key' => '...',
    'password'     => '...',
]);
```

Оба параметра обязательные строки (`Rule::object(...)->throwable()`).

## Токен запроса и подпись уведомлений

Т‑Банк подписывает данные одним и тем же алгоритмом: из массива убирается ключ `Token`, добавляется `Password`, оставляются только скалярные значения, массив сортируется по ключу (`ksort`), значения конкатенируются в порядке отсортированных ключей и хешируются SHA-256.

- **`generateToken(array $params, string $password): string`** (`public static`) — вычисляет токен для исходящего запроса. Используется автоматически в событии `encode_request`: перед каждым вызовом в `$params` подставляются `TerminalKey` (из настроек) и `Token` (результат `generateToken()` от уже собранных параметров, включая `TerminalKey`).
- **`verifyNotificationToken(array $data, string $password): bool`** (`public static`) — верификация подписи входящего webhook-уведомления от Т‑Банка. Берёт `Token` из `$data`, пересчитывает ожидаемый токен той же процедурой и сравнивает через `hash_equals()` (регистронезависимо). Возвращает `false`, если `Token` в данных отсутствует.

## HTTP-пайплайн

- `before_curl_init` — все запросы отправляются `POST` с `Content-Type: application/json`.
- `prepare_response` — при HTTP-коде вне 200–299 весь ответ считается ошибкой; иначе тело декодируется как JSON (если ещё не декодировано) и, если `Success === false`, в `$raw_data['error']` кладётся `Message`/`Details`/весь декодированный ответ.

## Зарегистрированные методы (`registerMethodsMap`)

- **`Init`** — инициализация платежа: `Amount` (обязательное положительное число, в копейках, приводится к `int`), `OrderId` (обязательная строка), `Description`, `Recurrent` (`Y`/`N`, по умолчанию `N`), `CustomerKey`, `SuccessURL`/`FailURL`/`NotificationURL` (URL), `PayType` (`O`/`T`, по умолчанию `O`), `Language` (`ru`/`en`, по умолчанию `ru`), `DATA`/`Receipt` (произвольные массивы).
- **`Charge`** — списание по рекуррентному платежу: `PaymentId`, `RebillId` (обязательные строки).
- **`GetState`** — статус платежа: `PaymentId` (обязательная строка).
- **`Cancel`** — отмена/возврат платежа: `PaymentId` (обязательная строка), `Amount` (опциональный `int`, частичная отмена).
- **`Confirm`** — подтверждение двухстадийного платежа: `PaymentId` (обязательная строка), `Amount` (опциональный `int`).
- **`GetCardList`** — список сохранённых карт клиента: `CustomerKey` (обязательная строка).
- **`RemoveCard`** — удаление сохранённой карты: `CustomerKey`, `CardId` (обязательные строки).
- **`Resend`** — повторная отправка уведомления по платежу: `PaymentId` (обязательная строка).

## Примеры использования

```php
$driver = TBank::create([
    'terminal_key' => getenv('TBANK_TERMINAL_KEY'),
    'password'     => getenv('TBANK_PASSWORD'),
]);

// инициализировать платёж
$init = $driver->call('Init', [
    'Amount'          => 150000, // 1500.00 руб. в копейках
    'OrderId'         => 'ORDER-42',
    'Description'     => 'Оплата брони №42',
    'NotificationURL' => 'https://example.com/webhooks/tbank',
]);

// проверить статус
$state = $driver->call('GetState', ['PaymentId' => $init['PaymentId']]);

// отменить/вернуть платёж
$driver->call('Cancel', ['PaymentId' => $init['PaymentId']]);

// в обработчике webhook-уведомления — проверить токен перед доверием телу запроса
if (!TBank::verifyNotificationToken($_POST, getenv('TBANK_PASSWORD'))) {
    http_response_code(400);
    exit('bad token');
}
```
