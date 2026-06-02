# ImageMime.php

> Документация сгенерирована AI-агентом на основе исходного кода.

## 1. Концепция

MIME-сервис для `image/*`. Поддерживает конвертацию формата и масштабирование через GD или Imagick. Результат кэшируется навсегда (TTL=-1).

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

### `convert(array $config = []): File`
Преобразование и масштабирование. Параметры: `extension` (целевой формат), `quality` (0–100), `width`, `height`, `side`, `object-fit` (fill/cover/contain/crop), `force`.

### `getImageSize(): array`
`{width, height, side}`. Результат кэшируется в метаданных файла.

### `static getImageDriver(): string`
`'imagick'`, `'gd'` или `''`.

### `static getAllowedExtension(): array`
Список доступных расширений для текущего драйвера.