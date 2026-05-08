# Assets

> Документация сгенерирована AI-агентом на основе исходного кода.

## 1. Концепция

`Assets` — менеджер CSS/JS/font/SVG-ресурсов в HTML-страницах. Основная идея — **буферизация**: HTML-фрагменты (теги `<link>`, `<script>`, `<style>`) накапливаются в именованные буферы (например, `'head'`), а затем выводятся в нужном месте шаблона.

**Сценарий использования:**

1. В нужном месте шаблона вызываетесь `Assets::mount('head')` — открывает буфер.
2. Любой код после может добавлять ресурсы через `Assets::add_css(...)` и др.
3. В конце секции вызывается `Assets::render('head')` — выводит накопленные ресурсы.

Один ресурс добавляется лишь один раз (md5-дедубликация по HTML-фрагменту).

```php
// В header.php:
Assets::mount('head');
// ... здесь могут добавляться ресурсы из любого паршиала

// В любом паршиале:
Assets::add_css('/assets/app.css');
Assets::add_js('/assets/app.js', ['defer' => 'defer']);
Assets::add_font('/fonts/Roboto-Regular.woff2');

// В footer.php (или сразу после блока mount):
Assets::render('head');
```

```php
// SVG-инлайн с санитизацией id
echo Assets::svg('/icons/logo.svg', ['class' => 'logo']);

// SVG-спрайт
$icon = Assets::sprite('arrow-right', ['class' => 'icon'], '/icons/sprite.svg');

// Инлайн-строка
Assets::add_string('<meta name="theme-color" content="#fff">', 'head');

// Глоб-поиск файлов
$files = Assets::resources('/assets/*.css');
```

## 2. Конфигурация

| Ключ | По умолчанию | Описание |
|---|---|---|
| `default_buffer` | `'head'` | Имя буфера по умолчанию |
| `bufferization` | `true` | Режим буферизации (`false` = HTML-комментарии-плейсхолдеры) |

## 3. Публичные методы

### `static mount(string $name): void`
Открывает буфер. Выводит всё, что уже накоплено в этом буфере до вызова `mount`, затем начинает перехватывать об output buffer PHP.

### `static render(string $buffer): void`
Закрывает об буфер, добавляет содержимое в буфер и выводит всё накопленное. Буферы работают в порядке LIFO.

### `static render_html(string $html): string`
Для режима `bufferization = false`: заменяет HTML-комментарии-плейсхолдеры накопленным контентом любого буфера в переданном HTML-строке.

### `static add_css(string|array $files, array $attributes = [], string $buffer = ''): static`
Добавляет тег `<link rel="stylesheet">` в буфер. `$attributes` добавляются как HTML-атрибуты тега.

### `static add_js(string|array $files, array $attributes = [], string $buffer = ''): static`
Добавляет тег `<script src="...">`. Атрибут `type` по умолчанию `text/javascript`.

### `static add_font(string|array $files, array $attributes = [], string $buffer = ''): static`
Добавляет `@font-face` в `<style>`. Имя шрифта, начертание и вес определяются автоматически из имени файла через `weights_map` и `styles_map`.

### `static add_string(string|array $strings, string $buffer = ''): static`
Добавляет произвольную HTML-строку в буфер (полезно для `<meta>`, `<link rel="canonical">` и т.&nbsp;п.).

### `static resources(string $path, array $params = []): array`
Возвращает список файлов по пути (директория, глоб-паттерн, отдельный файл, URI). Поддерживает регулярные выражения.

### `static svg(string $path, array $attributes = [], bool $returnPath = false): string`
Возвращает инлайн SVG-разметку (через `DOMDocument` если доступен). ID-атрибуты уникализируются добавлением суффикса (`_N`), что позволяет вставлять однин файл SVG несколько раз.

### `static sprite(string $iconId, array $attributes = [], string $path): string`
Возвращает `<svg><use xlink:href="...">` для символа из SVG-спрайта.

### `static full_path(string $path): string`
Преобразует путь: `~` = `$_SERVER['DOCUMENT_ROOT']`, относительный путь относительно `__DIR__`.

### `static is_uri(string $pathOrUri): bool`
Возвращает `true`, если строка является валидным URL..php
