<!-- DOCGEN:START -->
# Common

## Файлы

- [ContactPoint.php](ContactPoint.php.md)
- [GeoCoordinates.php](GeoCoordinates.php.md)
- [ImageObject.php](ImageObject.php.md)
- [PostalAddress.php](PostalAddress.php.md)
- [PropertyValue.php](PropertyValue.php.md)
- [Reference.php](Reference.php.md)

<!-- DOCGEN:END -->

Общие вложенные схемы, переиспользуемые несколькими типами `SchemaOrg`. Собственного
`print()` ни у одной нет — они встраиваются в родителя через `toArray()`.

Резолюция `@ref` в `DefaultSchema` поднимается только вверх по неймспейсу самой схемы, поэтому
из `Organization`, `Product`, `Article` и т.п. на них ссылаются **по FQCN в массиве-спецификации**:

```php
use ST_system\Schemas\SchemaOrg\Common\PostalAddress;

'address' => [PostalAddress::class, 'sometimes'],
'identifiers' => [self::arrayOf(PropertyValue::class), 'sometimes'],
```

Оба формата поддержаны `DefaultSchema` (пункты 6 и 8 раздела «Объявление полей» в
`../../DefaultSchema.php.md`).

Отдельно стоит `Reference` — она не описывает сущность, а печатает только `{"@id": "..."}`.
Именно ей связывается граф: `publisher`, `author`, `seller`, `manufacturer`, `about`,
`mainEntity`, `isPartOf` ссылаются на уже объявленную где-то на странице `Organization`
или `WebSite`, вместо того чтобы дублировать её поля.
