<!-- DOCGEN:START -->
# Offer.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\Product\Offer` — предложение о продаже товара
([schema.org/Offer](https://schema.org/Offer)). Вложенная схема, печатается через `toArray()`
из `../Product.php`.

## Поля

- **`price`** (обязательное, строка) — только число, без пробелов, валюты и копеек: `"25790"`.
- **`price_currency`** (обязательное) — код валюты по ISO 4217 (`RUB`).
- `url` — адрес страницы с предложением.
- `availability` — полный URI из словаря schema.org: `https://schema.org/InStock`,
  `https://schema.org/PreOrder`, `https://schema.org/OutOfStock`.
- `valid_through` — дата, до которой цена действительна; печатается как `priceValidUntil`.
- `seller` — `Common\Reference` на `@id` продавца.

## Вывод

```json
{
  "@type": "Offer",
  "url": "https://example.com/catalog/solnechnye-moduli/hvl-445hjt/",
  "priceCurrency": "RUB",
  "price": "25790",
  "availability": "https://schema.org/PreOrder",
  "seller": { "@id": "https://example.com/#organization" }
}
```
