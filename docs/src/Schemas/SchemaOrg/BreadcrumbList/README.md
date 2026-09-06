<!-- DOCGEN:START -->
# BreadcrumbList

## Файлы

- [ListItem.php](ListItem.php.md)

<!-- DOCGEN:END -->

`ListItem` — вложенная схема одного звена хлебных крошек, используется полем `items`
родительской схемы `BreadcrumbList` (`../BreadcrumbList.php`). Создаётся автоматически при
`BreadcrumbList::fill(['items' => [...]])`, отдельно не используется.

Не путать с `../ItemList/ListItem.php`: у того элемент списка описывается ключами
`url`/`item_*` и годится для перечня товаров в `ItemList`/`CollectionPage`, здесь же нужна
форма крошек — `position` + `name` + `item` в виде URL строкой.
