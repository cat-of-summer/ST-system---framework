<!-- DOCGEN:START -->
# PostalAddress.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\Service\PostalAddress` — вложенная схема [schema.org/PostalAddress](https://schema.org/PostalAddress), используемая полем `address` схемы `Service\Provider` (ссылка `@postal-address`). Наследует `DefaultSchema`. Все поля опциональны.

## Поля

- **`street_address`** → `streetAddress`.
- **`address_locality`** → `addressLocality`.
- **`postal_code`** → `postalCode`.
- **`address_country`** → `addressCountry`.

## Вывод (`toArray()`)

`{"@type": "PostalAddress"}` плюс только те поля, что были заданы.

## Пример

```php
use ST_system\Schemas\SchemaOrg\Service\PostalAddress;

$address = PostalAddress::create()->fill([
    'address_locality' => 'Москва',
    'street_address'   => 'ул. Примерная, 1',
    'postal_code'      => '101000',
]);
```

Обычно создаётся автоматически внутри `Provider::fill(['address' => [...]])`.
