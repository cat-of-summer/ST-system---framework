<!-- DOCGEN:START -->
# FontMime.php
<!-- DOCGEN:END -->

## Назначение

`FontMime` — обработчик веб-шрифтов, зарегистрированный под префиксом MIME `font/` (`woff2`, `woff`, `ttf`, `otf`, `eot`). Умеет определять семейство, начертание (`weight`) и стиль (`style`) шрифта — как по имени файла, так и, для бинарных форматов (`ttf`/`otf`/`woff`), напрямую из таблицы `name` шрифта — и рендерить `@font-face`.

Подмешивает трейты:
- `ST_system\Storage\Mimes\Traits\Minifiable`
- `ST_system\Storage\Mimes\Traits\Combinable`

## Публичные методы

- `purge(bool $storage = true): void` — сбрасывает закешированные метаданные (`$this->metadata`) и делегирует в `parent::purge()`.
- `getMetadata(): array` — возвращает метаданные шрифта: `weight`, `style`, `format`, `family`, `display`. Сначала парсит имя файла (`parseFilename()`), затем, если формат бинарный, дополняет/переопределяет значения через разбор двоичной таблицы имён шрифта (`readBinaryMetadata()`, результат кешируется в `CacheManager` по `mtime` файла). Результат кешируется в памяти на время жизни объекта.
- `__minify(string $content, array $config): string` (static) — делегирует минификацию в `CssMime::__minify()` (используется, если шрифт сопровождается CSS-описанием через `Minifiable`).
- `toHTML(array $config = []): string` — рендерит `<style>@font-face{...}</style>` на основе `getMetadata()`, объединённых с переданным `$config` (переданные ключи перекрывают вычисленные).

## Protected-контракт для трейтов

- `__combine(array $files, array $config): string` — конкатенирует `toHTML()` каждого файла (используется для сборки нескольких `@font-face` в один блок).
- `__combineExtension(): string` — возвращает `'css'`.

## Конфиг

Через `HasConfig`/`getDefaultConfig()` задаются таблицы соответствий: `weight` (текстовые ключи вроде `bold`, `medium` → числовое значение CSS `font-weight`), `style` (`italic`, `oblique`), `format` (расширение файла → значение для `format()` в `src: url(...)`) и `cache_dir` для кеша метаданных бинарных шрифтов.

## Примеры вызова

```php
// Метаданные шрифта (семейство, начертание, стиль, формат)
$meta = $file->getMetadata();

// Готовый <style>@font-face{...}</style>
echo $file->toHTML(['display' => 'block']);

// Сброс кеша метаданных
$file->purge();
```
