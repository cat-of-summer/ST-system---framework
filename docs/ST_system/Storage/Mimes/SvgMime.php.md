# SvgMime.php

> Документация сгенерирована AI-агентом на основе исходного кода.

## 1. Концепция

MIME-сервис для `image/svg+xml`. Работа со спрайтами SVG (фильтрация символов `<symbol>`).

```php
$svg = File::make('~/assets/icons.svg');

// Основное использование: `Assets::svg()` или `Assets::sprite()`

// Прямой вызов:
echo $svg->bySprite('arrow', ['class' => 'icon']); // <svg class='icon'><use xlink:href='icons.svg#arrow'></use></svg>
echo $svg->extractSprite('arrow', ['width' => '24', 'height' => '24']); // встроенный SVG
```

## 2. Публичные методы

### `bySprite(string $id, array $config = []): string`
`<svg><use xlink:href="...#id"></use></svg>`. `$config` — дополнительные SVG-атрибуты.

### `extractSprite(string $id, array $config = []): string`
Извлекает `<symbol id>` из SVG-файла и возвращает как полноценный SVG-тег (инлайн). Использует `DOMDocument` или regex как фоллбэк.