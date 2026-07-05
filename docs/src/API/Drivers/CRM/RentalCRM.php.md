# RentalCRM


## 1. Концепция

Драйвер [RetailCRM API v5](https://docs.retailcrm.ru/Developers/API). Эндпоинт строится автоматически по `subdomain`: `https://{subdomain}.retailcrm.ru/api/v5`. Параметр `apiKey` автоматически подставляется во все запросы.

```php
$crm = RentalCRM::create([
    'subdomain' => 'myshop',
    'api_key'   => 'abc123',
]);

$orders = $crm->call('orders', ['filter' => ['ids' => [42, 43]]]);

$orderId = $crm->call('orders/create', [
    'site'  => 'main',
    'order' => [
        'customer'        => ['externalId' => 'user-1'],
        'customerComment' => 'Быстрая доставка',
    ],
]);

$crm->call('files/upload', ['file' => '/var/www/uploads/doc.pdf']);
```

## 2. Публичные методы

### `static create(array $PARAMS): static`
Параметры: `subdomain` (обязат.), `api_key` (опц.).

### `call(string $method, array $params): mixed`

| Метод | Описание |
|---|---|
| `orders` (GET) | Список заказов. `filter[ids]` — массив ID (опц.). |
| `orders/create` (POST) | Создание. `site` (обязат.), `order[customer]`, `order[customerComment]`. |
| `customers` (GET) | Список клиентов. `filter[name]` (опц.). |
| `customers/create` (POST) | Создание. `site`, `customer[firstName/lastName/email/phones/tags]`. |
| `tasks` (GET) | Список задач. |
| `tasks/create` (POST) | Создание задачи. `site`, `task[performerId, customer, order, text]`. |
| `files/upload` (POST) | Загрузка файла. `file` — путь к файлу (авто-преобразуется в `CURLFile`). |.php
