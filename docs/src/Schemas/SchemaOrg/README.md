<!-- DOCGEN:START -->
# SchemaOrg

## Папки

- [FaqPage](FaqPage/)
- [ItemList](ItemList/)
- [Service](Service/)

## Файлы

- [FaqPage.php](FaqPage.php.md)
- [ItemList.php](ItemList.php.md)
- [MedicalProcedure.php](MedicalProcedure.php.md)
- [Service.php](Service.php.md)

<!-- DOCGEN:END -->

Конкретные типы разметки schema.org, все наследуют `Schemas\DefaultSchema` и печатаются как JSON-LD:

- **`FaqPage`** — страница вопросов-ответов; вложенные вопросы — `FaqPage\Question`.
- **`ItemList`** — именованный список элементов; элементы — `ItemList\ListItem`.
- **`MedicalProcedure`** — самостоятельная схема медицинской процедуры (без вложенных под-схем).
- **`Service`** — оказываемая услуга; связывает три вложенные под-схемы из `Service/`: `Provider` (поставщик услуги, сам ссылается на `PostalAddress`), `Offer` (предложение/цена), `OfferCatalog` (каталог из нескольких предложений).

Вложенные под-схемы (в `FaqPage/`, `ItemList/`, `Service/`) не имеют собственного `print()` — они встраиваются в родителя через `toArray()` и резолвятся автоматически по `@ref`-ссылкам (`'sometimes|@provider'` и т.п.), без ручного создания объектов.
