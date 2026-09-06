<!-- DOCGEN:START -->
# Article.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\Article` — материал: статья, новость, кейс
([schema.org/Article](https://schema.org/Article)). Конкретный подтип задаётся полем `type`,
отдельных классов под `NewsArticle`/`BlogPosting` нет — схема у них одна.

## Поля

| Поле | Правило | В JSON-LD |
|---|---|---|
| `type` | строка, по умолчанию `Article` | `@type` — `NewsArticle`, `BlogPosting`, … |
| `url` | обязательное, url | `url` и `mainEntityOfPage.@id` |
| `headline` | обязательное | `headline` |
| `description` | строка | `description` |
| `image` | массив абсолютных URL | `image` |
| `in_language` | строка | `inLanguage` |
| `date_published`, `date_modified` | строка | `datePublished`, `dateModified` |
| `author`, `publisher` | `Common\Reference` | ссылки на `@id` организации или автора |

`headline` берут из H1 страницы, а не из `<title>`: у листингов и служебных страниц title
шаблонный и с заголовком материала расходится.

Даты — ISO 8601 с таймзоной: `2026-09-01T00:00:00+03:00`. Схема принимает готовую строку и
формат не проверяет: приводить дату к ISO — задача вызывающего кода. Если осмысленной даты
у материала нет, поле лучше опустить, чем подставить сегодняшнюю.

## Пример использования

```php
use ST_system\Schemas\SchemaOrg\Article;

echo Article::create()->fill([
    'type'           => 'NewsArticle',
    'url'            => 'https://example.com/about/news/novaya-ses/',
    'headline'       => 'Хевел построил новую СЭС',
    'description'    => 'Станция мощностью 30 МВт введена в эксплуатацию.',
    'image'          => ['https://example.com/loaded/pages/ses.jpg'],
    'in_language'    => 'ru',
    'date_published' => '2026-09-01T00:00:00+03:00',
    'date_modified'  => '2026-09-01T00:00:00+03:00',
    'author'         => ['id' => 'https://example.com/#organization'],
    'publisher'      => ['id' => 'https://example.com/#organization'],
])->print();
```
