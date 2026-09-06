<!-- DOCGEN:START -->
# Schemas

## Папки

- [OpenGraph](OpenGraph/)
- [SchemaOrg](SchemaOrg/)
- [Yandex](Yandex/)

## Файлы

- [DefaultSchema.php](DefaultSchema.php.md)

<!-- DOCGEN:END -->

Пространство схем структурированных данных. `DefaultSchema` — общий движок (объявление полей через `getFields()`, валидация через `Rule`, связывание вложенных схем через `@ref`/`arrayOf`/`oneOf`, генерация вывода через `getPrint()`/`getToArray()`) — от него наследуют все конкретные схемы в трёх поддиректориях:

- **`SchemaOrg/`** — типы разметки [schema.org](https://schema.org) (Organization, WebSite, BreadcrumbList, Product, Article, WebPage, CollectionPage, FAQPage, ItemList, MedicalProcedure, Service и их вложенные части), выводятся как JSON-LD (`<script type="application/ld+json">`).
- **`OpenGraph/`** — [Open Graph](https://ogp.me/) и Twitter Card: не JSON-LD, а `<meta>`-теги, из которых соцсети и мессенджеры собирают превью ссылки. На сегодня единственная схема — `Meta`.
- **`Yandex/`** — форматы фидов Яндекса; на сегодня единственный — `MedicalFeed/`, медицинский XML-фид (справочник врачей/клиник).

Прежде чем документировать новую схему, см. `DefaultSchema.php.md` — там подробно разобраны все форматы спецификации полей и жизненный цикл `fill()`/`print()`/`toArray()`.
