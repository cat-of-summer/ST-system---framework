# Bitrix24


## 1. Концепция

Драйвер [Bitrix24 REST API](https://dev.1c-bitrix.ru/rest_help/). Пользовательский рест-эндпоинт задаётся через `endpoint` при создании. Схемы методов расширяются через `extendFields`/`extendParams`. Алиасы правил: `date` (в `Y-m-d`), `bool` (в `Y`/`N`), `multifield` (поля телефон/email).

```php
$b24 = Bitrix24::create(['endpoint' => 'https://portal.bitrix24.ru/rest/1/abc123/']);

// Расширение схемы перед использованием
$b24->extendFields('crm.contact.add', ['UF_MY_FIELD' => 'nullable|string']);

$b24->call('crm.contact.add', [
    'FIELDS' => [
        'NAME'  => 'Иван',
        'PHONE' => [['VALUE' => '+79001112233', 'VALUE_TYPE' => 'MOBILE']],
        'EMAIL' => [['VALUE' => 'ivan@example.com', 'VALUE_TYPE' => 'WORK']],
        'UF_MY_FIELD' => 'значение',
    ],
]);

$contacts = $b24->call('crm.contact.list', [
    'SELECT' => ['ID', 'NAME', 'PHONE'],
    'FILTER' => ['NAME' => 'Иван'],
    'PAGE'   => 0,
]);
```

## 2. Публичные методы

### `static create(array $PARAMS = []): static`
Параметры: `endpoint` (обязат., URL).

### `extendFields(string $method, array $fields): self`
Добавляет пользовательские поля в схему `FIELDS` метода. Вызывается до первого `call`.

### `extendParams(string $method, array $params): self`
Добавляет пользовательские поля в схему `PARAMS` метода.

### `call(string $method, array $params): mixed`

| Метод | Описание |
|---|---|
| `calendar.event.get` | События календаря. `type` (user/group/company_calendar), `ownerId` — обязат. + `section`, `from`, `to`. |
| `crm.contact.list` | Список контактов. `SELECT`, `FILTER`, `ORDER`, `PAGE` (пагинация автомат, START = PAGE*50). |
| `crm.contact.add` | Создание контакта. `FIELDS`: NAME, PHONE, EMAIL (мультифильды) + пользов. поля. |
| `crm.deal.add` | Создание сделки. `FIELDS`: TITLE, TYPE_ID, STAGE_ID, BEGINDATE/CLOSEDATE (date), OPPORTUNITY (float) и друг. |.php
