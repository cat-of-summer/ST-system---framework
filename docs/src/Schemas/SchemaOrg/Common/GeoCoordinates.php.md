<!-- DOCGEN:START -->
# GeoCoordinates.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\Common\GeoCoordinates` — географические координаты точки
([schema.org/GeoCoordinates](https://schema.org/GeoCoordinates)). Вложенная схема.

## Поля

- **`latitude`** (обязательное, строка) — широта.
- **`longitude`** (обязательное, строка) — долгота.

Строки, а не числа: в JSON-LD допустимы оба варианта, а строка не теряет незначащие нули
и не зависит от локали при форматировании float.

## Вывод

```json
{ "@type": "GeoCoordinates", "latitude": "55.65336", "longitude": "37.53811" }
```
