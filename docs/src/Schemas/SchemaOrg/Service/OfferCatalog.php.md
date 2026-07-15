<!-- DOCGEN:START -->
# OfferCatalog.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\Service\OfferCatalog` — вложенная схема [schema.org/OfferCatalog](https://schema.org/OfferCatalog), используемая полем `has_offer_catalog` схемы `Service` — каталог из нескольких предложений (в отличие от `Offer`, который описывает одно предложение). Наследует `DefaultSchema`.

## Поля

- **`name`** (обязательное) — название каталога.
- **`items`** (опционально) — **не** является схемой-ссылкой (`@ref`) или `arrayOf`, а принимается как обычный массив ассоциативных массивов вида `['type' => ..., 'name' => ..., 'url' => ...]` — валидируется правилом `'sometimes'` без строгой структуры, форматирование в `itemOffered`/`Offer` происходит вручную внутри `getToArray()`.

## Вывод (`toArray()`)

`{"@type": "OfferCatalog", "name": ...}`, плюс, если заданы `items`, `itemListElement` — массив объектов `{"@type": "Offer", "itemOffered": {"@type": item.type ?? "Service", "name": item.name, "url": item.url}}`.

## Пример

```php
use ST_system\Schemas\SchemaOrg\Service\OfferCatalog;

$catalog = OfferCatalog::create()->fill([
    'name'  => 'Услуги отделения',
    'items' => [
        ['type' => 'MedicalProcedure', 'name' => 'Консультация', 'url' => 'https://example.com/consult'],
        ['type' => 'MedicalProcedure', 'name' => 'УЗИ'],
    ],
]);
```

Обратите внимание: элементы `items` — обычные PHP-массивы, а не вложенные объекты `DefaultSchema` (в отличие от `arrayOf`-полей вроде `FaqPage::questions`).
