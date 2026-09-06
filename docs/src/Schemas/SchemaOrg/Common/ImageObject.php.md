<!-- DOCGEN:START -->
# ImageObject.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\Common\ImageObject` — изображение с размерами
([schema.org/ImageObject](https://schema.org/ImageObject)). Вложенная схема; в
`../Organization.php` ей описывается `logo`.

## Поля

- **`url`** (обязательное) — абсолютный URL, проходит валидацию правилом `url`
  (`FILTER_VALIDATE_URL`), поэтому относительный путь не пройдёт.
- `width`, `height` (целые) — размеры в пикселях.
- `caption` — подпись.

## Вывод

```json
{ "@type": "ImageObject", "url": "https://example.com/logo.png", "width": 512, "height": 512 }
```
