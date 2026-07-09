# FontMime.php

## 1. Концепция

MIME-сервис для `font/*`. Автоматически распознаёт `font-weight`, `font-style` и формат по имени файла.

```php
$font = File::make('~/fonts/Roboto-Bold.woff2');
echo $font->toHTML();
/*
<style>@font-face {
    font-family: 'Roboto';
    src: url('...') format('woff2');
    font-weight: 700;
    font-style: normal;
    font-display: swap;
}</style> */
```

Для `ttf`, `otf` и `woff` дополнительно читается таблица `name` из бинарника — оттуда берутся настоящие `family`, `weight` и `style`, которые перекрывают догадки по имени файла. Разбор кэшируется в `<basename>.fontmeta` со штампом `mtime`: пересохранили шрифт — метаданные перечитались.

## 2. Публичные методы

### `parseFilename(): array`
Расшифровать имя файла в `{weight, style, format, family, display}`. Слова `Bold`/`Light`/`Thin`, `Italic`/`Oblique` распознаются без учёта регистра.

### `getMetadata(): array`
`parseFilename()`, поверх которого наложены данные из бинарной таблицы `name` (если формат её поддерживает). Результат запоминается в объекте; сбрасывается через `purge()`.

Если бинарный разбор упал (битый файл, неподдерживаемая структура), кэшируется частичный результат — он детерминирован для данного содержимого файла, и повторное чтение всего шрифта при каждом вызове не имеет смысла.

### `purge(bool $storage = true): void`
Сбрасывает запомненные метаданные и кэш трейта `Minifiable`.

### `toHTML(array $config = []): string`
`@font-face` CSS-блок. `$config` переопределяет значения из `parseFilename()`.