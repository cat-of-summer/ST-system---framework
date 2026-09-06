<!-- DOCGEN:START -->
# Product.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\Product` — товар
([schema.org/Product](https://schema.org/Product)). Цена и условия продажи выносятся в
вложенную схему `Product\Offer` (`@ref`-имя `offer`).

## Поля

| Поле | Правило | В JSON-LD |
|---|---|---|
| `name` | обязательное | `name` |
| `url` | url | `url` |
| `description` | строка | `description` |
| `image` | массив абсолютных URL | `image` |
| `sku`, `mpn` | строка | `sku`, `mpn` |
| `category` | строка | `category` |
| `brand` | строка | `brand` — оборачивается в `{"@type": "Brand", "name": …}` |
| `manufacturer` | `Common\Reference` | `manufacturer` — ссылка на `@id` организации |
| `additional_property` | `arrayOf(Common\PropertyValue)` | `additionalProperty` — таблица характеристик |
| `offers` | `@offer` | `offers` |

`manufacturer` ставится только тому, что организация действительно производит сама; для
покупных комплектующих под своим брендом остаётся один `brand`.

## Товар без цены

`offers` необязателен. Если цены на странице нет, блок `Offer` не заполняют вовсе: `Offer`
без `price` невалиден, а `Product` сам по себе всё равно полезен — даёт производителя,
изображения и характеристики.

## Пример использования

```php
use ST_system\Schemas\SchemaOrg\Product;

echo Product::create()->fill([
    'name'         => 'Солнечный модуль HVL-445/HJT',
    'url'          => 'https://example.com/catalog/solnechnye-moduli/hvl-445hjt/',
    'description'  => 'Гетероструктурный солнечный модуль мощностью 445 Вт.',
    'image'        => ['https://example.com/loaded/catalog/goods/hvl-445.jpg'],
    'brand'        => 'Hevel',
    'manufacturer' => ['id' => 'https://example.com/#organization'],
    'additional_property' => [
        ['name' => 'Номинальная мощность', 'value' => '445', 'unit_text' => 'Вт'],
        ['name' => 'Эффективность', 'value' => '19.84', 'unit_text' => '%'],
    ],
    'offers' => [
        'url'            => 'https://example.com/catalog/solnechnye-moduli/hvl-445hjt/',
        'price'          => '25790',
        'price_currency' => 'RUB',
        'availability'   => 'https://schema.org/PreOrder',
        'seller'         => ['id' => 'https://example.com/#organization'],
    ],
])->print();
```

`AggregateRating` и `Review` схемой намеренно не поддержаны: выдумывать рейтинг там, где нет
реальных оценок, — нарушение требований поисковых систем.
