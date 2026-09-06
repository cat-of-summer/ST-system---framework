<!-- DOCGEN:START -->
# PostalAddress.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\Common\PostalAddress` — почтовый адрес
([schema.org/PostalAddress](https://schema.org/PostalAddress)). Вложенная схема, печатается
только через `toArray()`.

## Поля

Все необязательные (`sometimes|string`): `street_address`, `address_locality`,
`address_region`, `postal_code`, `address_country`. В вывод попадают только заполненные.

## Вывод

```json
{
  "@type": "PostalAddress",
  "streetAddress": "ул. Профсоюзная, д. 65, к. 1",
  "addressLocality": "Москва",
  "postalCode": "117342",
  "addressCountry": "RU"
}
```

## Отличие от `Service\PostalAddress`

Одноимённая схема есть и в `../Service/PostalAddress.php` — она осталась там ради
`Service\Provider`, который резолвит её как `@postal-address` внутри своего неймспейса.
Версия из `Common` — общая: в ней дополнительно есть `address_region`, и именно её
подключают по FQCN схемы вне `Service/`.
