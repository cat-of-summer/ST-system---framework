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

## 2. Публичные методы

### `parseFilename(): array`
Расшифровать имя файла в `{weight, style, format, family, display}`. Слова `Bold`/`Light`/`Thin`, `Italic`/`Oblique` распознаются без учёта регистра.

### `toHTML(array $config = []): string`
`@font-face` CSS-блок. `$config` переопределяет значения из `parseFilename()`.