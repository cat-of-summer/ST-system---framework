# JavaScriptMime.php

> Документация сгенерирована AI-агентом на основе исходного кода.

## 1. Концепция

MIME-сервис для `application/javascript`. Использует трейт `Minifiable`. JavaScript- минифайкер (JSMin-подобное) реализован внутри класса.

```php
$js = File::make('~/assets/app.js');
echo $js->toHTML(['defer' => true]); // <script src='...' defer></script>
$min = $js->minify();                // ~/cache/app.min.js
```

## 2. Публичные методы

### `toHTML(array $config = []): string`
`<script src='...' type='...'...></script>`. Конфиг: `type` (def. `text/javascript`), `async` (bool), `defer` (bool).

### `minify(array $config = []): File`
Минифицирует JS. Результат кэшируется навсегда (TTL=-1).