<!-- DOCGEN:START -->
# Offer.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\Service\Offer` — вложенная схема [schema.org/Offer](https://schema.org/Offer), используемая полем `offers` схемы `Service`. Наследует `DefaultSchema`. Встраивается через `toArray()`, собственного `print()` не имеет.

## Поля

- **`price`** (обязательное) — строка (цена как текст, не число — так требует schema.org).
- **`price_currency`** (обязательное) → `priceCurrency` (например `RUB`, `USD`).
- **`url`** (опционально).
- **`availability`** (опционально) — строка (например `https://schema.org/InStock`).
- **`valid_through`** (опционально) → `validThrough`.

## Вывод (`toArray()`)

`{"@type": "Offer", "price": ..., "priceCurrency": ...}` плюс опциональные поля, если заданы.

## Пример

```php
use ST_system\Schemas\SchemaOrg\Service\Offer;

$offer = Offer::create()->fill([
    'price'          => '1500',
    'price_currency' => 'RUB',
    'availability'   => 'https://schema.org/InStock',
]);
```

На практике обычно создаётся автоматически при `Service::fill(['offers' => [...]])`.
