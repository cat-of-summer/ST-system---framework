# CloudPayments

## 1. Концепция

Драйвер [CloudPayments API](https://cloudpayments.ru). Автентификация через HTTP Basic (`public_id:api_secret`). Все запросы отправляются методом POST.

```php
$cp = CloudPayments::create([
    'public_id'  => 'pk_xxxx',
    'api_secret' => 'sk_xxxx',
]);

$order = $cp->call('orders/create', [
    'Amount'      => 1500.00,
    'Currency'    => 'RUB',
    'Description' => 'Оплата заказа #42',
    'InvoiceId'   => '42',
]);
```

## 2. Публичные методы

### `static create(array $PARAMS): static`
Параметры: `public_id`, `api_secret`.

### `call(string $method, array $params): mixed`

| Метод | Описание |
|---|---|
| `test` | Проверка соединения. |
| `payments/find` | Поиск платежа. `InvoiceId` — обязат. |
| `orders/create` | Создание ссылки на оплату. `Amount`, `Currency` (RUB/EUR/USD), `Description` — обязат. |
| `orders/cancel` | Отмена ссылки. `Id` — обязат. |
| `site/notifications/{Type}/update` | Настройка вебхуков. `Type`: `Pay`/`Fail`/`Confirm`/`Refund`/`Recurrent`/`Cancel`. |.php
