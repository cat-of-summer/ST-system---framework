# ItemList.php

`ST_system\Schemas\SchemaOrg\ItemList` — Schema.org разметка списка элементов. Выводит `<script type="application/ld+json">` с типом `ItemList`.

## Поля

| Поле | Обяз. | Тип | Описание |
|------|-------|-----|----------|
| `name` | да | string | Название списка |
| `description` | нет | string | Описание |
| `url` | нет | url | URL страницы со списком |
| `number_of_items` | нет | int | Количество элементов (если не указано — считается по `items`) |
| `items` | нет | ListItem[] | Массив элементов списка |

## Использование

```php
use ST_system\Schemas\SchemaOrg\ItemList;

$list = (new ItemList())->fill([
    'name' => 'Список услуг клиники',
    'url'  => 'https://clinic.ru/services',
    'items' => [
        ['position' => 1, 'name' => 'УЗИ', 'url' => 'https://clinic.ru/services/uzi'],
        ['position' => 2, 'name' => 'МРТ', 'url' => 'https://clinic.ru/services/mrt'],
        [
            'position'  => 3,
            'item_type' => 'MedicalProcedure',
            'item_name' => 'Консультация кардиолога',
            'item_url'  => 'https://clinic.ru/services/cardio',
        ],
    ],
]);

echo $list->print();
// <script type="application/ld+json">{"@context":"https://schema.org","@type":"ItemList",...}</script>
```
