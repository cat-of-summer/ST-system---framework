<!-- DOCGEN:START -->
# ListItem.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\ItemList\ListItem` — вложенная схема [schema.org/ListItem](https://schema.org/ListItem), используемая внутри `ItemList` (поле `items`). Наследует `DefaultSchema`. Как и `FaqPage\Question`, не печатается сама по себе — только встраивается через `toArray()`.

## Поля

- **`position`** (обязательное) — int, позиция элемента в списке (1-based по конвенции schema.org).
- **`name`** / **`url`** (опционально) — название и ссылка самого пункта списка.
- **`item_type`** (опционально) — `@type` вложенной сущности `item` (по умолчанию `Thing`, если заданы `item_name`/`item_url`).
- **`item_name`** / **`item_url`** (опционально) — название/ссылка вложенной сущности, которую представляет элемент списка.

## Вывод (`toArray()`)

Всегда содержит `@type: "ListItem"` и `position`. `name`/`url` добавляются, если заданы. Если задан `item_name` или `item_url` — добавляется вложенный объект `item` (`@type` = `item_type` или `Thing`, плюс `name`/`url`).

## Пример

```php
use ST_system\Schemas\SchemaOrg\ItemList\ListItem;

$item = ListItem::create()->fill([
    'position'  => 1,
    'item_type' => 'MedicalProcedure',
    'item_name' => 'УЗИ брюшной полости',
    'item_url'  => 'https://example.com/uzi',
]);
```

Обычно создаётся автоматически внутри `ItemList::fill(['items' => [...]])`.
