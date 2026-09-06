<!-- DOCGEN:START -->
# ListItem.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\BreadcrumbList\ListItem` — звено хлебных крошек
([schema.org/ListItem](https://schema.org/ListItem)). Вложенная схема, печатается через
`toArray()` из родительского `../BreadcrumbList.php`.

## Поля

- **`position`** (обязательное, целое) — номер звена, нумерация с 1 и без пропусков.
- **`name`** (обязательное) — подпись звена.
- `item` (url) — адрес страницы звена. **Не задаётся у последнего элемента** — текущая
  страница ссылается сама на себя, и валидаторы считают это ошибкой.

## Вывод

```json
{ "@type": "ListItem", "position": 2, "name": "Каталог", "item": "https://example.com/catalog/" }
```
