<!-- DOCGEN:START -->
# RentalCRM.php
<!-- DOCGEN:END -->

`final class RentalCRM extends IntegrationDriver` (`ST_system\API\Drivers\CRM\RentalCRM`) — интеграция с [RetailCRM](https://www.retailcrm.ru/) (API v5) под именем "Rental" в этом проекте. Один из CRM-драйверов (наравне с `Bitrix24`). В отличие от `Bitrix24`, класс не объявляет дополнительных публичных методов и не расширяет схемы валидации — вся функциональность выражена целиком через карту методов `IntegrationDriver`, зарегистрированную в `__init()`.

## Конструктор

```php
RentalCRM::create([
    'subdomain' => 'your-company',       // обязательный, непустая строка
    'api_key'   => 'xxxxxxxxxxxxxxxx',   // опциональный API-ключ
]);
```

Из `subdomain` конструктор вычисляет `endpoint = "https://{subdomain}.retailcrm.ru/api/v5"` (с проверкой `filter_var(..., FILTER_VALIDATE_URL)`) и убирает `subdomain` из итоговых `$SETTINGS` — дальше используется только готовый `endpoint`.

## HTTP-пайплайн

- `build_url` — склеивает `endpoint` из настроек с именем вызываемого метода.
- `before_curl_init` — на каждый запрос автоматически добавляется параметр `apiKey` из настроек (RetailCRM API v5 аутентифицирует запросы через query/body-параметр, а не через заголовок).

## Зарегистрированные методы (`registerMethodsMap`)

- **`orders`** (`GET`) — список заказов: `filter` — опциональный массив с `ids` (массив целых чисел).
- **`orders/create`** (`POST`) — создание заказа: `site` (обязательная строка), `order` (обязательный массив, должен содержать `customer` с одним из ключей `externalId`/`id`/`browserId`, опционально `customerComment`); `on_prepare` сериализует `order` в JSON перед отправкой (так требует RetailCRM API — вложенные сущности передаются JSON-строкой внутри form-параметра).
- **`customers`** (`GET`) — список клиентов: `filter` — опциональный массив с `name`.
- **`customers/create`** (`POST`) — создание клиента: `site` (обязательная строка), `customer` (обязательный массив: `firstName`/`lastName`/`patronymic`, `email` (валидный email или `null`), `phones` (массив объектов с обязательным `number`), `tags` (массив строк)); `on_prepare` сериализует `customer` в JSON.
- **`files/upload`** (`POST`) — загрузка файла: `file` — путь к существующему читаемому файлу, автоматически оборачивается в `\CURLFile` перед отправкой (multipart).
- **`tasks`** (`GET`) — список задач, без параметров.
- **`tasks/create`** (`POST`) — создание задачи: `site` (обязательная строка), `task` (обязательный массив: `customer` — один из `externalId`/`id`, `order` — один из `externalId`/`id`/`number`, `performerId` (обязательный int), `text`/`commentary`); `on_prepare` сериализует `task` в JSON.

## Примеры использования

```php
$driver = RentalCRM::create([
    'subdomain' => 'your-company',
    'api_key'   => getenv('RETAILCRM_API_KEY'),
]);

// создать заказ
$driver->call('orders/create', [
    'site'  => 'main',
    'order' => [
        'customer'        => ['externalId' => 'user-42'],
        'customerComment' => 'Оплата картой, доставка курьером',
    ],
]);

// найти клиентов по имени
$customers = $driver->call('customers', ['filter' => ['name' => 'Иван']]);

// загрузить файл (например, скан документа)
$driver->call('files/upload', ['file' => '/path/to/scan.pdf']);

// создать задачу на менеджера
$driver->call('tasks/create', [
    'site' => 'main',
    'task' => [
        'order'       => ['externalId' => 'order-42'],
        'performerId' => 7,
        'text'        => 'Перезвонить клиенту по заказу №42',
    ],
]);
```
