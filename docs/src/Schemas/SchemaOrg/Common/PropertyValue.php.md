<!-- DOCGEN:START -->
# PropertyValue.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\Common\PropertyValue` — пара «название — значение»
([schema.org/PropertyValue](https://schema.org/PropertyValue)). Вложенная схема, обслуживает
два разных места:

- `additional_property` в `../Product.php` — технические характеристики товара;
- `identifiers` в `../Organization.php` — реестровые идентификаторы (ОГРН, КПП), для которых
  в schema.org нет отдельных полей.

## Поля

- **`name`** (обязательное) — название характеристики.
- **`value`** (обязательное, строка) — значение.
- `property_id` — код характеристики, если он есть (`"ОГРН"`, GUID из учётной системы).
- `unit_text` — единица измерения (`"Вт"`, `"%"`, `"мм"`).

Единицу лучше класть в `unit_text`, а не приписывать к `value` — тогда значение остаётся
машиночитаемым.

## Вывод

```json
{ "@type": "PropertyValue", "name": "Номинальная мощность", "value": "445", "unitText": "Вт" }
```
