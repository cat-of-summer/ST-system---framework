<!-- DOCGEN:START -->
# ItemList.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\ItemList` — схема [schema.org/ItemList](https://schema.org/ItemList): именованный список элементов (например, список услуг или статей). Наследует `DefaultSchema` (см. `docs/src/Schemas/DefaultSchema.php.md`). Элементы списка описываются вложенной схемой `ItemList\ListItem`.

## Поля

- **`name`** (обязательное) — строка, название списка.
- **`description`** (опционально) — строка.
- **`url`** (опционально) — URL.
- **`number_of_items`** (опционально) — int; если не задано, при печати подставляется `count(items)`.
- **`items`** (опционально) — массив вложенных `ListItem` (`arrayOf('list-item')`).

## Вывод

`print()` собирает JSON-LD с `@type: "ItemList"`, `numberOfItems` и, если элементы заданы, `itemListElement` (массив `toArray()` каждого `ListItem`). Поля `description`/`url` попадают в вывод только если заданы.

## Пример

```php
use ST_system\Schemas\SchemaOrg\ItemList;

$list = ItemList::create()->fill([
    'name'  => 'Наши услуги',
    'items' => [
        ['position' => 1, 'item_name' => 'Консультация терапевта', 'item_url' => 'https://example.com/therapist'],
        ['position' => 2, 'item_name' => 'УЗИ', 'item_url' => 'https://example.com/uzi'],
    ],
]);

echo $list->print();
```
