<!-- DOCGEN:START -->
# SchemaOrg

## Папки

- [BreadcrumbList](BreadcrumbList/)
- [Common](Common/)
- [FaqPage](FaqPage/)
- [ItemList](ItemList/)
- [Product](Product/)
- [Service](Service/)

## Файлы

- [Article.php](Article.php.md)
- [BreadcrumbList.php](BreadcrumbList.php.md)
- [CollectionPage.php](CollectionPage.php.md)
- [FaqPage.php](FaqPage.php.md)
- [ItemList.php](ItemList.php.md)
- [MedicalProcedure.php](MedicalProcedure.php.md)
- [Organization.php](Organization.php.md)
- [Product.php](Product.php.md)
- [Service.php](Service.php.md)
- [WebPage.php](WebPage.php.md)
- [WebSite.php](WebSite.php.md)

<!-- DOCGEN:END -->

Конкретные типы разметки schema.org, все наследуют `Schemas\DefaultSchema` и печатаются как JSON-LD.

**Якорные схемы сайта** — объявляются один раз в глобальном шаблоне и получают собственный `@id`:

- **`Organization`** — организация: реквизиты, логотип, адрес, координаты, точки контакта, профили в соцсетях.
- **`WebSite`** — сайт целиком; `publisher` ссылается на `@id` организации.

**Схемы страниц** — ссылаются на якорные по `@id` через `Common\Reference`:

- **`BreadcrumbList`** — хлебные крошки; звенья — `BreadcrumbList\ListItem`.
- **`Product`** — товар; цена и наличие — во вложенном `Product\Offer`, характеристики — массивом `Common\PropertyValue`.
- **`Article`** — статья, новость или кейс; подтип (`Article`, `NewsArticle`, …) задаётся полем `type`.
- **`WebPage`** — обычная страница; подтип `WebPage`/`AboutPage`/`ContactPage` задаётся полем `type`.
- **`CollectionPage`** — страница-листинг; перечень элементов уходит в `mainEntity` типа `ItemList`, элементы — `ItemList\ListItem`.
- **`FaqPage`** — страница вопросов-ответов; вложенные вопросы — `FaqPage\Question`.
- **`ItemList`** — самостоятельный именованный список элементов; элементы — `ItemList\ListItem`.
- **`MedicalProcedure`** — медицинская процедура (без вложенных под-схем).
- **`Service`** — оказываемая услуга; связывает три вложенные под-схемы из `Service/`: `Provider` (поставщик услуги, сам ссылается на `PostalAddress`), `Offer` (предложение/цена), `OfferCatalog` (каталог из нескольких предложений).

Вложенные под-схемы (в `BreadcrumbList/`, `Common/`, `FaqPage/`, `ItemList/`, `Product/`, `Service/`) не имеют собственного `print()` — они встраиваются в родителя через `toArray()`. Схемы из своей папки резолвятся автоматически по `@ref`-ссылкам (`'sometimes|@offer'` и т.п.), общие из `Common/` — по FQCN в массиве-спецификации (`[Common\PostalAddress::class, 'sometimes']`), потому что `@ref` ищет класс только вверх по собственному неймспейсу схемы.
