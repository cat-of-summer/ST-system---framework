# Robokassa

> Документация сгенерирована AI-агентом на основе исходного кода.

## 1. Концепция

Драйвер [Robokassa](https://docs.robokassa.ru). Содержит набор статических хелперов для подписей и нудей (`Shp_`), метод генерации ссылки оплаты, повторяющиеся платежи (рекурринг) и получение статуса платежа через XML (OpStateExt).

```php
$robokassa = Robokassa::create([
    'merchant_login' => 'MyShop',
    'password1'      => 'pass1',
    'password2'      => 'pass2',
    'test_mode'      => true,
]);

// Генерация ссылки на оплату
$url = $robokassa->createPaymentUrl([
    'OutSum'      => 990.00,
    'InvId'       => 42,
    'Description' => 'Оплата заказа #42',
    'Recurring'   => true,
]);

// Проверка вебхука
if (Robokassa::verifyResultSignature($_POST, 'pass2')) {
    // платеж успешен
}

// Повторяющийся платёж
$result = $robokassa->chargeRecurring([
    'InvoiceID'         => 43,
    'PreviousInvoiceID' => 42,
    'OutSum'            => 990.00,
]);

// Статус платежа
$state = $robokassa->getOperationState(42);
echo Robokassa::mapStateCode($state['state_code']); // 'completed'
```

## 2. Публичные методы

### `static create(array $PARAMS): static`
Параметры: `merchant_login`, `password1`, `password2`, `hash_algo` (md5/sha1/sha256, дополнит. md5), `test_mode` (bool).

### `createPaymentUrl(array $params): string`
Генерирует URL для перенаправления пользователя на страницу оплаты. Подпись `SignatureValue` рассчитывается автоматически. Параметры: `OutSum`, `InvId`, `Description`, `Email`, `Recurring` (bool), `Shp_params`.

### `chargeRecurring(array $params): array`
Повторяющийся платёж (`Merchant/Recurring`). Параметры: `InvoiceID`, `PreviousInvoiceID`, `OutSum`, `Description`, `Email`, `Shp_params`.

### `getOperationState(int|string $invoiceId): array`
Получение статуса платежа через XML-интерфейс OpStateExt. Подпись рассчитывается автоматически.

### `static hashSignature(string $data, string $algo = 'md5'): string`
### `static buildInitSignature(...): string`
Подпись инициализации по формуле: `MerchantLogin:OutSum:InvId:Password1[:Shp_*]`.
### `static buildResultSignature(...): string`
Подпись вебхука ResultURL: `OutSum:InvId:Password2[:Shp_*]`.
### `static buildSuccessSignature(...): string`
Подпись SuccessURL: `OutSum:InvId:Password1[:Shp_*]`.
### `static verifyResultSignature(array $data, string $password2, string $algo = 'md5'): bool`
Проверка `SignatureValue` вебхука `ResultURL`.
### `static mapStateCode(int $stateCode): string`
Преобразование кода OpStateExt в строку: `completed` (100), `processing` (50), `hold` (20), `pending` (5/80), `canceled` (10), `refunded` (60), `unknown`.
### `static extractShpParams(array $data): array`
Извлекает `Shp_*` параметры из массива..php
