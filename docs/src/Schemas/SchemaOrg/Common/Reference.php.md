<!-- DOCGEN:START -->
# Reference.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\Common\Reference` — ссылка на сущность по `@id`. Не описывает
никакой тип schema.org: печатает ровно один ключ.

## Поля

- **`id`** (обязательное, строка) — значение `@id` сущности, объявленной в другом блоке
  JSON-LD на той же странице.

## Вывод

```json
{ "@id": "https://www.hevelsolar.com/#organization" }
```

## Зачем

Это способ связать блоки разметки в граф. Организация объявляется один раз — в глобальном
шаблоне через `../Organization.php` с собственным `@id`, а все остальные схемы страницы
(`Product.manufacturer`, `Product.offers.seller`, `Article.author`/`publisher`,
`WebSite.publisher`, `WebPage.about`/`mainEntity`, `isPartOf`) ссылаются на неё через
`Reference`, а не повторяют её поля. Поисковик по совпадающим `@id` понимает, что речь об
одной и той же организации.

```php
use ST_system\Schemas\SchemaOrg\Common\Reference;

'publisher' => [Reference::class, 'sometimes'],
// ...
$schema->fill(['publisher' => ['id' => 'https://example.com/#organization']]);
```
