# CssMime.php

> Документация сгенерирована AI-агентом на основе исходного кода.

## 1. Концепция

MIME-сервис для `text/css`. Использует трейт `Minifiable` для минификации CSS с кэшированием.

```php
$css = File::make('~/assets/style.css');
echo $css->toHTML();           // <link rel='stylesheet' href='...' type='text/css'>
echo $css->toHTML(['media' => 'print']); // + media='print'
$min = $css->minify();         // ~/cache/style.min.css
```

## 2. Публичные методы

### `toHTML(array $config = []): string`
`<link rel='stylesheet' href='...' type='...' media='...'>`. Конфиг: `type` (def. `text/css`), `media`.

### `minify(array $config = []): File`
Минифицирует CSS: удаляет комментарии, лишние пробелы. Результат кэшируется навсегда (TTL=-1).