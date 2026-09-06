<!-- DOCGEN:START -->
# WebPage.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\WebPage` — обычная страница сайта
([schema.org/WebPage](https://schema.org/WebPage)). Подтип задаётся полем `type`: `AboutPage`
для страниц о компании, `ContactPage` для контактов, `WebPage` для всего остального.

Для страниц-листингов есть отдельная схема — `CollectionPage.php`: у неё внутри `ItemList`.

## Поля

- **`url`** (обязательное), **`name`** (обязательное) — адрес и заголовок страницы.
- `type` — `@type`, по умолчанию `WebPage`.
- `description` — обычно meta description.
- `in_language` — язык страницы.
- `is_part_of` — `Common\Reference` на `@id` сайта (`WebSite`).
- `about` — `Common\Reference` на то, чему страница посвящена (обычно организация).
- `main_entity` — `Common\Reference` на главную сущность страницы; для `ContactPage` это
  сама организация: адрес, телефон и почту здесь не дублируют, а ссылаются по `@id`.

## Пример использования

```php
use ST_system\Schemas\SchemaOrg\WebPage;

echo WebPage::create()->fill([
    'type'        => 'ContactPage',
    'url'         => 'https://example.com/contacts/',
    'name'        => 'Контакты',
    'in_language' => 'ru',
    'is_part_of'  => ['id' => 'https://example.com/#website'],
    'main_entity' => ['id' => 'https://example.com/#organization'],
])->print();
```
