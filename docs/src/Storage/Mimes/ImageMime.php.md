# ImageMime.php


## 1. Концепция

MIME-сервис для `image/*`. Поддерживает конвертацию формата и масштабирование через GD или Imagick. Результат кэшируется навсегда (TTL=-1) и перестраивается только при изменении `mtime` исходника (либо по `force`).

Требует GD или Imagick — при их отсутствии конструктор сервиса бросает исключение. Поскольку сервис резолвится лениво, `File::make('photo.png')` сам по себе не упадёт; исключение появится при первом обращении к `toHTML()`, `convert()`, `getImageSize()` или `getServiceName()`.

```php
$img = File::make('~/images/photo.jpg');

// Простой <img>
echo $img->toHTML(['class' => 'hero']);

// Конвертация + ресайз
$webp = $img->convert(['extension' => 'webp', 'width' => 800, 'quality' => 80]);
echo $webp->getPathname(); // ~/cache/photo.800.webp

// Респонсивный <img srcset=...>
echo $img->toResponsive(
    ['extension' => 'webp', 'viewport' => ['sm' => '100vw', 'lg' => '800px']]
);
```

## 2. Публичные методы

### `toHTML(array $config = []): string`
`<img src='...' alt='...' />`. `$config` — произвольные HTML-атрибуты.

### `toResponsive(array $config = [], array $attrs = []): string`
Генерирует `<img srcset="..." sizes="...">`. `$config`: `extension`, `viewport` (отображение для названных вьюпортов), `quality`, `sizes`.

`sizes` фильтруется по **ключам** карты `resize.sizes`, поэтому передавать нужно `['thumb' => true, 'large' => true]`, а не `['thumb', 'large']`.

### `convert(array $config = []): File`
Преобразование и масштабирование. Параметры: `extension` (целевой формат), `quality` (0–100), `width`, `height`, `side`, `object-fit` (fill/cover/contain/crop), `force`.

Кэш перестраивается, если файла нет, передан `force`, либо штамп `stamp` в его метаданных не равен `mtime` исходника.

### `getImageSize(): array`
`{width, height, side}`.

Результат кэшируется в **отдельном мета-слоте** `<basename>.imagesize.meta` — без блоба, одним файлом. Три целых числа не стоят пары «блоб + мета»: чтение блоба в `FileSystemCacheDriver` — это ещё один цикл `flock` с созданием и удалением `.lock`-файла.

Инвалидация — по штампу `mtime` исходного изображения: пересохранили картинку, размеры пересчитались.

Слот **отдельный, а не мета самого файла**, по трём причинам:

1. Для URI мета файла — это запись HTTP-кэша, и `fetch()` / `getMeta()` перезаписывают её целиком (`append = false`). Значение бы терялось при каждом обновлении заголовков.
2. Чтобы пережить `purgeExpired()`, записи нужен `expires_in = -1`; у URI это ровно тот ключ, что управляет перекачкой, и `-1` сделал бы кэш бессмертным.
3. Ключ `stamp` не засоряет пространство имён пользовательского `File::setMeta()`.

```php
$size = $img->getImageSize();  // ['width' => 800, 'height' => 600, 'side' => 800]
```

### `static getImageDriver(): string`
`'imagick'`, `'gd'` или `''`.

### `static getAllowedExtension(): array`
Список доступных расширений для текущего драйвера.