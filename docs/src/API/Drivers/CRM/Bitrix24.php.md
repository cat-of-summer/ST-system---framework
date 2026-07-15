<!-- DOCGEN:START -->
# Bitrix24.php
<!-- DOCGEN:END -->

`final class Bitrix24 extends IntegrationDriver` (`ST_system\API\Drivers\CRM\Bitrix24`) — интеграция с REST API [Битрикс24](https://dev.1c-bitrix.ru/rest_help/) (через входящий вебхук или иную точку REST-доступа портала). Один из CRM-драйверов проекта (наравне с `RentalCRM`). Отличительная особенность класса — расширяемые схемы валидации `FIELDS`/`PARAMS` для методов создания CRM-сущностей: вызывающий код может дополнить встроенный набор полей своими кастомными полями Битрикс24 (`UF_*` и т.п.) без форка драйвера.

## Конструктор

```php
Bitrix24::create([
    'endpoint' => 'https://your-portal.bitrix24.ru/rest/1/xxxxxxxxxxxxxxxx/',
]);
```

`endpoint` — обязательный валидный URL (проверяется `filter_var(..., FILTER_VALIDATE_URL)`), хвостовой `/` обрезается. `build_url` склеивает его с именем вызываемого метода.

## Правила-алиасы, регистрируемые в области видимости класса

В `__init()` (внутри `Rule::scope(static::class, ...)`, см. `IntegrationDriver`) регистрируются алиасы `Rule`, которые затем используются в схемах параметров:

- **`date`** — принимает `null`, `\DateTimeInterface` или строку с датой; приводит значение к формату `Y-m-d` (для `\DateTimeInterface` — напрямую, для строки — через `new \DateTime($v)` с проверкой `DateTime::getLastErrors()`); при некорректной дате бросает исключение "Некорректная дата".
- **`bool`** — принимает `null`, `bool` или `'Y'`/`'N'`; приводит булево значение к `'Y'`/`'N'` (формат, ожидаемый Битрикс24 для булевых полей).
- **`multifield`** — валидирует структуру "мультиполя" Битрикс24 (используется для `PHONE`/`EMAIL` и т.п.): `null`, либо массив элементов вида `['ID' => ?int, 'TYPE_ID' => enum PHONE|EMAIL|WEB|IM|LINK, 'VALUE' => непустая строка (обязательна), 'VALUE_TYPE' => enum (WORK, MOBILE, FAX, ... BITRIX24, ...)]`.

## Расширение схем: `extendFields()` / `extendParams()`

Публичные методы для донастройки валидации методов `crm.contact.add` и `crm.deal.add` (единственных, где заранее объявлена расширяемая схема `FIELDS`/`PARAMS`), без переопределения класса:

- **`extendFields(string $method, array $fields): self`** — домердживает дополнительные правила в схему `FIELDS` указанного метода (например, добавить кастомное поле сделки `UF_CRM_1234567890`).
- **`extendParams(string $method, array $params): self`** — то же самое для схемы `PARAMS` (общие параметры вызова, не относящиеся к полям сущности, например `REGISTER_SONET_EVENT`).

Оба метода возвращают `$this` (fluent-цепочка) и хранят добавленные правила в приватном `$extraSchemas[$method]['FIELDS'|'PARAMS']`, которые подмешиваются (`array_merge`) в базовую схему при каждом вызове соответствующего метода.

## Зарегистрированные методы (`registerMethodsMap`)

- **`calendar.event.get`** — список событий календаря: `type` (`user`/`group`/`company_calendar`), `ownerId` (int), `section` (строка или массив строк — `on_prepare` превращает его в `section[]` для REST-формата Битрикс24), `from`/`to` (`nullable|date`).
- **`crm.contact.list`** (`POST`) — список контактов: `SELECT`/`FILTER`/`ORDER` (произвольные массивы), `START`/`PAGE` (int; `on_prepare` переводит человекопонятный `PAGE` в offset `START = PAGE * 50` и убирает `PAGE`). Если в `FILTER.PHONE` передан телефон — `on_prepare` очищает его от нецифровых символов.
- **`crm.contact.add`** (`POST`) — создание контакта: `FIELDS` (базовая схема `NAME`, `PHONE`/`EMAIL` как `multifield`, плюс всё, что добавлено через `extendFields('crm.contact.add', ...)`), `PARAMS` (базово `REGISTER_SONET_EVENT`, плюс `extendParams('crm.contact.add', ...)`). `on_prepare` очищает `FIELDS.PHONE.VALUE` от нецифровых символов.
- **`crm.deal.add`** (`POST`) — создание сделки: обширная встроенная схема `FIELDS` (`TITLE`, `TYPE_ID`, `CATEGORY_ID`, `STAGE_ID`, флаги `IS_*` как `bool`, `PROBABILITY` (0–100), `OPPORTUNITY`/`TAX_VALUE` (float), `COMPANY_ID`/`CONTACT_ID`/`CONTACT_IDS`, `BEGINDATE`/`CLOSEDATE` (`date`), `OPENED`/`CLOSED` (`bool`), `UTM_*` и др.), расширяемая через `extendFields('crm.deal.add', ...)`; `PARAMS` — аналогично `crm.contact.add`, расширяется через `extendParams('crm.deal.add', ...)`.

## Примеры использования

```php
$driver = Bitrix24::create(['endpoint' => getenv('BITRIX24_WEBHOOK_URL')]);

// добавить кастомное поле в схему сделки перед первым вызовом
$driver->extendFields('crm.deal.add', [
    'UF_CRM_SOURCE_CHANNEL' => 'nullable|string',
]);

$dealId = $driver->call('crm.deal.add', [
    'FIELDS' => [
        'TITLE'       => 'Заявка с сайта №42',
        'CONTACT_ID'  => 15,
        'BEGINDATE'   => new \DateTime('now'),
        'UF_CRM_SOURCE_CHANNEL' => 'site',
    ],
]);

$driver->call('crm.contact.add', [
    'FIELDS' => [
        'NAME'  => 'Иван',
        'PHONE' => [['VALUE' => '+7 999 123-45-67', 'VALUE_TYPE' => 'MOBILE']],
    ],
]);

$contacts = $driver->call('crm.contact.list', [
    'FILTER' => ['PHONE' => '+7 999 123-45-67'],
    'PAGE'   => 0,
]);

$events = $driver->call('calendar.event.get', [
    'type'    => 'user',
    'ownerId' => 15,
    'from'    => new \DateTime('-7 days'),
    'to'      => new \DateTime('now'),
]);
```
