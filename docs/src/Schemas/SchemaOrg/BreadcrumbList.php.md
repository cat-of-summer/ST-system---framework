<!-- DOCGEN:START -->
# BreadcrumbList.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\BreadcrumbList` — хлебные крошки
([schema.org/BreadcrumbList](https://schema.org/BreadcrumbList)). Элементы описываются
вложенной схемой `BreadcrumbList\ListItem` — она резолвится по `@ref`-имени `list-item`
внутри собственного неймспейса.

## Поля

- **`items`** (обязательное) — массив вложенных схем `ListItem` (маркер `arrayOf('list-item')`).

## Вывод

```json
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [ /* toArray() каждого ListItem */ ]
}
```

## Пример использования

```php
use ST_system\Schemas\SchemaOrg\BreadcrumbList;

echo BreadcrumbList::create()->fill([
    'items' => [
        ['position' => 1, 'name' => 'Главная', 'item' => 'https://example.com/'],
        ['position' => 2, 'name' => 'Каталог', 'item' => 'https://example.com/catalog/'],
        ['position' => 3, 'name' => 'HVL-445/HJT'],
    ],
])->print();
```

Последний (текущий) элемент цепочки идёт **без** `item` — у страницы, на которой стоит
разметка, ссылки на саму себя быть не должно, но `position` у неё обязателен.
