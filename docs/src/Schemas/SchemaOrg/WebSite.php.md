<!-- DOCGEN:START -->
# WebSite.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\WebSite` — сайт целиком
([schema.org/WebSite](https://schema.org/WebSite)). Вторая после `Organization.php` глобальная
схема: объявляется один раз в шаблоне, получает свой `@id`, и на неё ссылаются страницы
(`WebPage.isPartOf`, `CollectionPage.isPartOf`).

## Поля

- **`id`** (обязательное) — `@id` сайта, обычно `https://example.com/#website`.
- **`url`** (обязательное) — адрес главной страницы.
- **`name`** (обязательное) — название сайта.
- `description` — описание.
- `in_language` — язык версии сайта (`ru`, `en`); на многоязычном сайте меняется вместе с локалью.
- `publisher` — `Common\Reference` на `@id` организации.

## Пример использования

```php
use ST_system\Schemas\SchemaOrg\WebSite;

echo WebSite::create()->fill([
    'id'          => 'https://example.com/#website',
    'url'         => 'https://example.com/',
    'name'        => 'Хевел',
    'in_language' => 'ru',
    'publisher'   => ['id' => 'https://example.com/#organization'],
])->print();
```
