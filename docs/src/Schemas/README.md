<!-- DOCGEN:START -->
# Schemas

## Папки

- [SchemaOrg](SchemaOrg/)
- [Yandex](Yandex/)

## Файлы

- [DefaultSchema.php](DefaultSchema.php.md)

<!-- DOCGEN:END -->

Пространство схем структурированных данных. `DefaultSchema` — общий движок (объявление полей через `getFields()`, валидация через `Rule`, связывание вложенных схем через `@ref`/`arrayOf`/`oneOf`, генерация вывода через `getPrint()`/`getToArray()`) — от него наследуют все конкретные схемы в двух поддиректориях:

- **`SchemaOrg/`** — типы разметки [schema.org](https://schema.org) (FAQPage, ItemList, MedicalProcedure, Service и их вложенные части), выводятся как JSON-LD (`<script type="application/ld+json">`).
- **`Yandex/`** — форматы фидов Яндекса; на сегодня единственный — `MedicalFeed/`, медицинский XML-фид (справочник врачей/клиник).

Прежде чем документировать новую схему, см. `DefaultSchema.php.md` — там подробно разобраны все форматы спецификации полей и жизненный цикл `fill()`/`print()`/`toArray()`.
