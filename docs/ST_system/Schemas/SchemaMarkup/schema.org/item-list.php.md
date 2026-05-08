# item-list.php

> Документация сгенерирована AI-агентом на основе исходного кода.

## 1. Концепция

Микроразметка [schema.org/ItemList](https://schema.org/ItemList) в JSON-LD. Зарегистрирована в скопе `schema`.

```php
require_once 'item-list.php';

$markup = Schema::create('schema.item-list')->fill([
    'name'  => 'Основные направления',
    'items' => [
        ['position' => 1, 'item_type' => 'MedicalProcedure', 'item_name' => 'Липофилинг', 'item_url' => 'https://example.com/1'],
        ['position' => 2, 'item_type' => 'MedicalProcedure', 'item_name' => 'Ринопластика', 'item_url' => 'https://example.com/2'],
    ],
]);
echo $markup->print();
```

## 2. Поля

`name` (req), `description`, `url`, `number_of_items` (авто = `count(items)`), `items[]` (@list-item: `position`, `item_type`, `item_name`, `item_url`).