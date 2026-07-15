<!-- DOCGEN:START -->
# Robokassa.php
<!-- DOCGEN:END -->

`final class Robokassa extends IntegrationDriver` (`ST_system\API\Drivers\Acquiring\Robokassa`) — интеграция с платёжным шлюзом [Robokassa](https://robokassa.ru/). Один из платёжных драйверов проекта (наравне с `CloudPayments`, `TBank`). Особенность Robokassa в том, что основной способ оплаты — это редирект пользователя на страницу мерчанта с подписанными GET-параметрами, а не прямой server-to-server вызов; поэтому у класса, помимо стандартной карты методов `IntegrationDriver` (`Merchant/Recurring`, `Merchant/WebService/Service.asmx/OpStateExt`), есть набор `public static` хелперов для построения ссылок и подписей, а также `public` методов-обёрток над `call()`.

## Конструктор

```php
Robokassa::create([
    'merchant_login' => 'my_shop',
    'password1'      => '...', // пароль #1 — для инициализации платежа / Success-страницы
    'password2'      => '...', // пароль #2 — для Result-уведомлений / проверки статуса
    'hash_algo'      => 'md5', // необязательно: md5|sha1|sha256|sha384|sha512, по умолчанию md5
    'test_mode'      => false, // необязательно: включает тестовый режим (IsTest=1)
]);
```

## Подпись (signature)

Robokassa требует на каждом шаге хеш (по умолчанию MD5) от конкатенации параметров через `:`, с опциональным хвостом из пользовательских `Shp_*`-параметров (отсортированных по ключу `strnatcasecmp`, добавляемых как `:Key=Value`). Все билдеры подписи — `public static`:

- **`hashSignature(string $data, string $algo = 'md5'): string`** — низкоуровневая обёртка над `hash()`.
- **`buildInitSignature($merchantLogin, $outSum, $invId, $password1, $shpParams = [], $algo = 'md5')`** — подпись для **инициализации** платежа: `hash(algo, "{merchantLogin}:{outSum}:{invId}:{password1}" + Shp-хвост)`. Используется и в `createPaymentUrl()`, и внутри `encode_request` для `Merchant/Recurring`.
- **`buildSuccessSignature($outSum, $invId, $password1, $shpParams = [], $algo = 'md5')`** — подпись для проверки **Success-редиректа** (пользователь возвращается на сайт после оплаты): та же схема, но без `merchantLogin`, с `password1`.
- **`buildResultSignature($outSum, $invId, $password2, $shpParams = [], $algo = 'md5')`** — подпись для **Result-уведомления** (server-to-server webhook от Robokassa): аналогично, но с `password2`.
- **`verifyResultSignature(array $data, string $password2, string $algo = 'md5'): bool`** — берёт `SignatureValue`, `OutSum`, `InvId` и все `Shp_*`/`shp_*`-ключи из `$data` (тела вебхука), пересчитывает ожидаемую подпись через `buildResultSignature()` и сравнивает `hash_equals()` (регистронезависимо). Возвращает `false`, если `SignatureValue` не передан.
- **`buildOpStateSignature($merchantLogin, $invoiceId, $password2, $algo = 'md5')`** — подпись для запроса статуса операции (`OpStateExt`): `hash(algo, "{merchantLogin}:{invoiceId}:{password2}")`.
- **`extractShpParams(array $data): array`** — вытаскивает из массива все ключи, начинающиеся на `Shp_`/`shp_` (регистронезависимо).

## Другие статические хелперы

- **`generatePaymentUrl(array $params): string`** — склеивает `https://auth.robokassa.ru/Merchant/Index.aspx?...` с `http_build_query($params)`.
- **`mapStateCode(int $stateCode): string`** — переводит числовой код состояния операции Robokassa (`100`, `50`, `20`, `5`, `10`, `60`, `80`) в человекочитаемый статус (`completed`, `processing`, `hold`, `pending`, `canceled`, `refunded`; неизвестный код → `unknown`).

## Зарегистрированные методы и HTTP-пайплайн

- `before_curl_init` выбирает `POST` + `application/x-www-form-urlencoded` для методов, содержащих `Recurring` в имени, иначе `GET`.
- `encode_request` для `*Recurring*`-методов добавляет `MerchantLogin`, считает `SignatureValue` через `buildInitSignature()` и при `test_mode` добавляет `IsTest=1`; после этого `$params` всегда сериализуется в query-строку через `http_build_query()`.
- `prepare_response`: при HTTP-коде вне 200–299 — ошибка. Для `OpStateExt` ответ (XML) парсится приватным `parseOpStateXml()` в ассоциативный массив (`result_code`, `result_description`, `state_code`, суммы, `user_fields` и т.д.); ненулевой `result_code` считается ошибкой. Для `*Recurring*` ответ — обычный текст (`OK`/описание ошибки), приводится к `['raw' => ..., 'success' => bool]`.

Карта методов (`registerMethodsMap`):
- **`Merchant/Recurring`** — повторное списание по рекуррентному платежу: `InvoiceID`, `PreviousInvoiceID` (обязательные строки), `OutSum` (положительное число, приводится к строке с 2 знаками), `Description`, `Email` (опционально).
- **`Merchant/WebService/Service.asmx/OpStateExt`** — запрос статуса операции: `MerchantLogin`, `InvoiceID`, `Signature` (все обязательные строки).

## Публичные методы-обёртки (instance)

- **`createPaymentUrl(array $params): string`** — строит подписанную ссылку на оплату (не HTTP-вызов, а генерация URL для редиректа пользователя). Принимает `OutSum`, `InvId`, `Description`, опционально `Email`, `Recurring` (bool), `Shp_params` (массив пользовательских параметров, добавляется и в подпись, и в query).
- **`getOperationState($invoiceId): array`** — считает подпись через `buildOpStateSignature()` и вызывает `call('Merchant/WebService/Service.asmx/OpStateExt', ...)`.
- **`chargeRecurring(array $params): array`** — формирует параметры (`InvoiceID`, `PreviousInvoiceID`, `OutSum`, опционально `Description`/`Email`/`Shp_params`) и вызывает `call('Merchant/Recurring', ...)`.
- **`verifyWebhook(array $data): bool`** — проверка подписи входящего Result-уведомления, обёртка над `verifyResultSignature()` с `password2`/`hash_algo` из настроек инстанса.
- **`computeExpectedWebhookHash(array $data): string`** — та же логика, что и в `verifyWebhook()`, но возвращает ожидаемый хеш вместо `bool` (удобно для логирования/отладки несовпадений).
- **`getSettings(): array`** — возвращает сохранённые настройки инстанса (`merchant_login`, `password1`, `password2`, `hash_algo`, `test_mode`).

## Примеры использования

```php
$driver = Robokassa::create([
    'merchant_login' => 'my_shop',
    'password1'      => getenv('ROBOKASSA_PASS1'),
    'password2'      => getenv('ROBOKASSA_PASS2'),
]);

// 1) сгенерировать ссылку на оплату и отправить пользователя туда
$url = $driver->createPaymentUrl([
    'OutSum'      => 990.00,
    'InvId'       => 42,
    'Description' => 'Оплата брони №42',
    'Shp_params'  => ['Shp_order' => '42'],
]);

// 2) в обработчике Result-уведомления (webhook) проверить подпись
if (!$driver->verifyWebhook($_POST)) {
    http_response_code(400);
    exit('bad sign');
}

// 3) спросить состояние операции напрямую у Robokassa
$state = $driver->getOperationState(42);
echo Robokassa::mapStateCode($state['state_code'] ?? 0);
```
