<!-- DOCGEN:START -->
# Place.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\Common\Place` — место
([schema.org/Place](https://schema.org/Place)). Вложенная схема, печатается только через
`toArray()`.

Нужна прежде всего ради координат: `geo` в schema.org объявлен у `Place`, а не у
`Organization` и не у `PostalAddress`, поэтому координаты организации прописываются
её `location`, а не ей самой.

## Поля

| Поле | Правило | В JSON-LD |
|---|---|---|
| `name` | строка | `name` — название места («Головной офис») |
| `url` | url | `url` |
| `address` | `Common\PostalAddress` | `address` |
| `geo` | `Common\GeoCoordinates` | `geo` |

## Вывод

```json
{
  "@type": "Place",
  "address": {
    "@type": "PostalAddress",
    "streetAddress": "ул. Профсоюзная, д. 65, к. 1",
    "addressLocality": "Москва",
    "postalCode": "117342",
    "addressCountry": "RU"
  },
  "geo": { "@type": "GeoCoordinates", "latitude": "55.65336", "longitude": "37.53811" }
}
```
