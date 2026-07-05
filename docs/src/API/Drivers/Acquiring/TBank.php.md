# TBank

## 1. Концепция

Драйвер [T-Bank (Tинькофф) Acquiring API](https://www.tbank.ru/kassa/dev/payments/). Все запросы отправляются POST+JSON. `Token` SHA256 автоматически генерируется по отсортированным параметрам + `Password` для каждого запроса.

```php
$tb = TBank::create([
    'terminal_key' => 'TinkoffBankTest',
    'password'     => 'TinkoffBankTest',
]);

$resp = $tb->call('Init', [
    'Amount'  => 10000, // копейки
    'OrderId' => 'order-42',
]);
$payUrl = $resp['PaymentURL'];

$state = $tb->call('GetState', ['PaymentId' => $resp['PaymentId']]);

// Проверка вебхука
$ok = TBank::verifyNotificationToken($_POST, 'TinkoffBankTest');
```

## 2. Публичные методы

### `static create(array $PARAMS): static`
Параметры: `terminal_key`, `password`.

### `call(string $method, array $params): mixed`

| Метод | Описание |
|---|---|
| `Init` | Приём платежа. `Amount` (копейки), `OrderId` — обязат. + `Description`, `Recurrent` (Y/N), `CustomerKey`, `SuccessURL`, `FailURL`, `NotificationURL`, `PayType` (O/T), `Language` (ru/en), `DATA`, `Receipt`. |
| `Charge` | Списание рекуррентного. `PaymentId`, `RebillId` — обязат. |
| `GetState` | Статус платежа. `PaymentId` — обязат. |
| `Cancel` | Отмена/возврат. `PaymentId` — обязат; `Amount` — опц. |
| `Confirm` | Подтверждение 2-этапного платежа. `PaymentId`, `Amount` (опц.). |
| `GetCardList` | Список сохранённых карт. `CustomerKey` — обязат. |
| `RemoveCard` | Удаление карты. `CustomerKey`, `CardId` — обязат. |
| `Resend` | Повторная отправка уведомлений. `PaymentId` — обязат. |

### `static verifyNotificationToken(array $data, string $password): bool`
Проверка `Token` входящего уведомления через `hash_equals`.

### `static generateToken(array $params, string $password): string`
Генерация Token: SHA256 отсортированные параметры + Password..php
