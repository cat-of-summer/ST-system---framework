<!-- DOCGEN:START -->
# ImageMime.php
<!-- DOCGEN:END -->

## Назначение

`ImageMime` — обработчик растровых изображений, зарегистрированный под префиксом MIME `image/` (кроме `image/svg+xml`, за который отвечает `SvgMime`). Отвечает за определение размеров изображения, конвертацию форматов, ресайз с разными стратегиями вписывания и генерацию `<img>`/responsive-разметки (`srcset`/`sizes`). Работает поверх `Imagick`, если расширение доступно, иначе — поверх GD.

Использует `ST_system\Traits\HasConfig` через алиас (`use HasConfig { config as traitConfig; }`), чтобы переопределить статический `config()` и лениво дополнить конфиг реальным списком поддерживаемых Imagick-форматов при первом обращении к `formats`/`formats.imagick`.

## Конфигурация

`getDefaultConfig()` задаёт: `cache_dir` (берётся из `File::config('cache.dir')`), `convert.config` (качество конвертации по умолчанию, флаг `force`), `formats.gd`/`formats.imagick` (какие расширения и через какую функцию/с поддержкой качества обрабатывает каждый драйвер), `resize.config` (стратегия вписывания по умолчанию), `resize.viewports` (пороги для `sizes` в responsive-разметке) и `resize.sizes` (именованные пресеты ширины для `srcset`).

## Публичные методы

- `config(string $key = '')` (static) — обёртка над `HasConfig::config()`: при первом обращении к ключам `formats`/`formats.imagick`, если активен драйвер Imagick, дополняет конфиг реальным списком поддерживаемых Imagick форматов (через `getAllowedExtension()`) и лишь затем возвращает значение.
- `getImageDriver(): string` (static) — определяет и кеширует (в статическом свойстве) используемый драйвер: `'imagick'`, если класс `Imagick` доступен, иначе `'gd'`, если доступен `gd_info()`, иначе пустая строка.
- `getAllowedExtension(): array` (static) — список расширений, реально поддерживаемых текущим драйвером и присутствующих в конфиге `formats.*` (пересечение возможностей рантайма и конфигурации).
- `toHTML(array $config = []): string` — рендерит простой `<img src="..." alt="...">`, где `$config` домешивается как дополнительные HTML-атрибуты.
- `toResponsive(array $config = [], array $attrs = []): string` — рендерит `<img>` с `srcset`/`sizes` для набора точек разрыва: конвертирует изображение в несколько ширин (`resize.sizes` или переданный `$config['sizes']`), формирует `srcset`, вычисляет `sizes` из `resize.viewports`/`$config['viewport']`. Поддерживает `$config['extension']` (по умолчанию `webp`) и `$config['quality']`.
- `getImageSize(): array` — возвращает `['width' => ..., 'height' => ..., 'side' => ...]` (сторона = максимум из ширины/высоты). Кеширует результат в `CacheManager` по `mtime` файла (валидация через `stamp` в meta кеша, не через `modified_at` источника).
- `convert(array $config = []): File` — универсальная конвертация/ресайз. Поддерживает: смену расширения (`$config['extension']`, в т. ч. спецкейс `'svg'` — оборачивает растровое изображение как `<image>` внутри SVG через `convertToSvgWrapper()`), качество (`$config['quality']`), ресайз по `width`/`height`/`side` (числа, `"NNpx"` или ключ из `resize.sizes`) со стратегией `object-fit` (`cover`/`contain`/`fill`). Результат кешируется на диске, ключ кеша учитывает размеры/качество/стратегию; повторная конвертация того же файла с тем же набором параметров использует кеш, пока `mtime` источника не изменится (или пока не передан `force`).

## Внутренние (private) методы

- `convertToSvgWrapper(File $instance, array $config): File` — оборачивает растровое изображение в SVG-контейнер с `<image href="data:...;base64,...">`, с кешированием по `mtime`.
- `convertImage(object $image, array $config): object` — низкоуровневая запись изображения в целевой формат через Imagick (`setImageFormat`/`writeImage`) или через соответствующую функцию GD (`imagejpeg`/`imagepng`/`imagewebp`/…), с приведением палитровых изображений к true color перед сохранением в WebP.
- `resizeImage(object $image_src, array $resize_config): object` — низкоуровневый ресайз/кроп на холст нужного размера через Imagick (`cropImage`+`resizeImage`+`compositeImage`) или GD (`imagecreatetruecolor`+`imagecopyresampled`), с сохранением альфа-канала.

## Примеры вызова

```php
// Простой <img>
echo $file->toHTML(['class' => 'logo']);

// Адаптивная разметка с srcset/sizes
echo $file->toResponsive(['extension' => 'webp']);

// Ресайз с указанием стороны и стратегии вписывания
$thumb = $file->convert(['side' => 'thumb', 'object-fit' => 'cover', 'extension' => 'webp']);

// Размеры оригинала
['width' => $w, 'height' => $h] = $file->getImageSize();
```

Как и у других MIME-обработчиков, все вызовы идут через `File`/`Resource`, а не напрямую на объект `ImageMime`.
