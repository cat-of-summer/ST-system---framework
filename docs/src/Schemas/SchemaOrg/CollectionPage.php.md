<!-- DOCGEN:START -->
# CollectionPage.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\CollectionPage` — страница-листинг
([schema.org/CollectionPage](https://schema.org/CollectionPage)): каталог, раздел новостей,
перечень проектов. Перечисленные на странице элементы уходят в `mainEntity` типа `ItemList`.

## Поля

- **`url`** (обязательное), **`name`** (обязательное) — адрес и заголовок листинга.
- `description`, `in_language`.
- `is_part_of` — `Common\Reference` на `@id` сайта.
- `items` — `arrayOf(ItemList\ListItem::class)`: переиспользуется вложенная схема из
  `ItemList/`, отдельного класса под элементы у `CollectionPage` нет.

`numberOfItems` считается по фактическому количеству элементов. Если `items` пуст, блок
`mainEntity` не печатается вовсе.

## Пример использования

```php
use ST_system\Schemas\SchemaOrg\CollectionPage;

echo CollectionPage::create()->fill([
    'url'         => 'https://example.com/catalog/solnechnye-moduli/',
    'name'        => 'Солнечные модули',
    'in_language' => 'ru',
    'is_part_of'  => ['id' => 'https://example.com/#website'],
    'items'       => [
        ['position' => 1, 'url' => 'https://example.com/catalog/solnechnye-moduli/hvl-445hjt/'],
        ['position' => 2, 'url' => 'https://example.com/catalog/solnechnye-moduli/hvl-400hjt/'],
    ],
])->print();
```

Список генерируют по карточкам, фактически выведенным на странице, и в порядке вывода.
