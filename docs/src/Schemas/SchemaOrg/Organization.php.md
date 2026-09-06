<!-- DOCGEN:START -->
# Organization.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\Organization` — организация
([schema.org/Organization](https://schema.org/Organization)). Якорная схема графа: объявляется
один раз в глобальном шаблоне сайта с собственным `@id`, а все прочие типы ссылаются на неё
через `Common\Reference`, вместо того чтобы повторять реквизиты.

В отличие от большинства схем реализует **и** `getPrint()`, **и** `getToArray()`: печатается
самостоятельным JSON-LD-блоком, но может быть и встроена в родительскую схему.

## Поля

| Поле | Правило | В JSON-LD |
|---|---|---|
| `id` | обязательное | `@id` |
| `type` | строка, по умолчанию `Organization` | `@type` — можно сузить до `LocalBusiness`, `Corporation` |
| `name` | обязательное | `name` |
| `legal_name` | строка | `legalName` — юридическое наименование |
| `tax_id` | строка | `taxID` — ИНН |
| `identifiers` | `arrayOf(Common\PropertyValue)` | `identifier` — реестровые коды без своих полей в schema.org (ОГРН, КПП) |
| `url` | url | `url` |
| `logo` | `Common\ImageObject` | `logo` |
| `telephone`, `email` | строка / e-mail | как есть |
| `address` | `Common\PostalAddress` | `address` |
| `geo` | `Common\GeoCoordinates` | `geo` |
| `area_served` | строка | `areaServed` — оборачивается в `{"@type": "Country", "name": …}` |
| `contact_points` | `arrayOf(Common\ContactPoint)` | `contactPoint` |
| `same_as` | массив | `sameAs` — профили в соцсетях и справочниках |

## Пример использования

```php
use ST_system\Schemas\SchemaOrg\Organization;

echo Organization::create()->fill([
    'id'         => 'https://example.com/#organization',
    'name'       => 'ГК Хевел',
    'legal_name' => 'ООО «Хевел Ритейл»',
    'tax_id'     => '2124045449',
    'identifiers' => [
        ['property_id' => 'ОГРН', 'name' => 'ОГРН', 'value' => '1182130009687'],
    ],
    'url'  => 'https://example.com/',
    'logo' => ['url' => 'https://example.com/logo.png', 'width' => 512, 'height' => 512],
    'telephone' => '+7-495-933-06-03',
    'email'     => 'info@example.com',
    'address'   => [
        'street_address'   => 'ул. Профсоюзная, д. 65, к. 1',
        'address_locality' => 'Москва',
        'postal_code'      => '117342',
        'address_country'  => 'RU',
    ],
    'geo'         => ['latitude' => '55.65336', 'longitude' => '37.53811'],
    'area_served' => 'Россия',
    'contact_points' => [
        ['contact_type' => 'sales', 'telephone' => '+7-495-933-06-03', 'available_language' => ['Russian']],
    ],
    'same_as' => ['https://vk.ru/example'],
])->print();
```

Вложенные схемы коэрсятся автоматически — передавать готовые объекты `PostalAddress`,
`ImageObject` и т.д. не нужно, достаточно ассоциативных массивов.
