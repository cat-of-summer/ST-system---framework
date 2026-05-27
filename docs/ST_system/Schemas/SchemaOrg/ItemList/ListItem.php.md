# ListItem.php

`ST_system\Schemas\SchemaOrg\ItemList\ListItem` — один элемент Schema.org `ItemList`. Используется внутри `ItemList` через поле `items`. Экспортирует данные через `toArray()` (не выводит `<script>` самостоятельно).

## Поля

| Поле | Обяз. | Тип | Описание |
|------|-------|-----|----------|
| `position` | да | int | Порядковый номер элемента в списке |
| `name` | нет | string | Название элемента |
| `url` | нет | url | URL элемента |
| `item_type` | нет | string | Тип вложенного объекта (`'MedicalProcedure'`, `'Service'`, `'Thing'`). Умолч: `'Thing'` |
| `item_name` | нет | string | Название вложенного объекта |
| `item_url` | нет | url | URL вложенного объекта |

## Использование

Как правило используется через `ItemList`, но можно и напрямую:

```php
use ST_system\Schemas\SchemaOrg\ItemList\ListItem;

$item = (new ListItem())->fill([
    'position'  => 1,
    'item_type' => 'MedicalProcedure',
    'item_name' => 'УЗИ брюшной полости',
    'item_url'  => 'https://clinic.ru/services/uzi',
]);

$arr = $item->toArray();
// [
//   '@type'    => 'ListItem',
//   'position' => 1,
//   'item'     => ['@type' => 'MedicalProcedure', 'name' => 'УЗИ брюшной полости', 'url' => '...']
// ]
```
