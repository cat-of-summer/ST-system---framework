# Assets.php

> Документация сгенерирована AI-агентом на основе исходного кода.

## 1. Концепция

`Assets` — менеджер CSS/JS/font/SVG-ресурсов для HTML-страниц. Работает в двух режимах:

- **Статический** — `Assets::addCss(...)`, `Assets::mount('head')` — пути передаются явно.
- **Инстанс** — `$a = Assets::create(__DIR__)`, затем `$a->addCss('app.css')` — все относительные пути разрешаются относительно базового каталога инстанса.

**Pipeline при выводе:** файлы одного типа с одинаковыми атрибутами → `combine()` → `minify()` → HTML-тег.

**Дедупликация:** один и тот же файл с теми же атрибутами добавляется в буфер только один раз (md5-ключ по пути + атрибутам).

```php
// header.php — открываем буфер
Assets::mount('head');

// Любой включаемый файл добавляет ресурсы
Assets::addCss('/assets/reset.css');
Assets::addCss('/assets/app.css');
Assets::addJs('/assets/app.js', ['defer' => true]);

// footer.php — закрываем буфер, выводим теги
Assets::render('head');
```

---

## 2. Конфигурация

```php
Assets::config([
    'default_buffer' => 'head',
    'bufferization'  => true,
    'css'   => ['combine' => true, 'minify' => true],
    'js'    => ['combine' => true, 'minify' => true],
    'fonts' => ['combine' => true, 'minify' => true],
]);
```

| Ключ | По умолчанию | Описание |
|---|---|---|
| `default_buffer` | `'head'` | Буфер, используемый когда `$buffer = ''` |
| `bufferization` | `true` | `true` — PHP ob_start; `false` — HTML-комментарии-плейсхолдеры |
| `css.combine` | `true` | Объединять CSS-файлы с одинаковыми атрибутами в один |
| `css.minify` | `true` | Минифицировать CSS (после combine) |
| `js.combine` | `true` | Объединять JS (кроме `type=module`) |
| `js.minify` | `true` | Минифицировать JS |
| `fonts.combine` | `true` | Объединять шрифтовые файлы |
| `fonts.minify` | `true` | Минифицировать шрифты |

---

## 3. Инстанс-режим

```php
$a = Assets::create(string $path, string $buffer = ''): self
```

Создаёт инстанс, привязанный к базовому пути. Все методы добавления ресурсов (`addCss`, `addJs`, `addFont`, `addResource`, `svg`, `sprite`, `addString`, `setManifest`) через инстанс автоматически разрешают относительные пути относительно `$path`.

Если `$path` — файл, базовый каталог берётся как его директория.

```php
// Удобно в шаблонах, где __DIR__ — папка шаблона
$a = Assets::create(__DIR__, 'head');
$a->addCss('css/style.css');    // => __DIR__ . '/css/style.css'
$a->addJs('js/main.js');        // => __DIR__ . '/js/main.js'
$a->sprite('arrow');
```

Если `$buffer` задан при создании, он используется по умолчанию во всех методах инстанса (если буфер не указан явно при вызове).

---

## 4. Буферы: mount / render / finalize

### `mount(string $name): void`

Открывает именованный буфер. Немедленно выводит всё, что уже накоплено в нём (ресурсы, добавленные до `mount`), затем запускает `ob_start()` для перехвата последующего вывода.

```php
Assets::mount('head');
// Теперь весь echo/print перехватывается
```

### `render(string $buffer): void`

Закрывает буфер (ob_get_clean), добавляет перехваченный HTML в накопленное, выводит всё накопленное, затем рендерит сгруппированные assets (CSS/JS/fonts).

Буферы работают в порядке **LIFO**: если открыты `mount('head')` и `mount('body')`, сначала должен быть закрыт `body`, потом `head`.

```php
Assets::render('head');
```

### `finalize(string $html): string`

Для режима `bufferization = false`. Заменяет HTML-комментарии-плейсхолдеры `<!-- Assets::mount("name") -->` накопленным контентом всех буферов. Возвращает итоговый HTML.

```php
Assets::config(['bufferization' => false]);
ob_start();
include 'page.php';
$html = ob_get_clean();
echo Assets::finalize($html);
```

---

## 5. Добавление ресурсов

### `addCss($href, array $attrs = [], string $buffer = ''): void`

Добавляет CSS-файл в буфер. При рендере формируется `<link rel="stylesheet" href="...">`.

```php
Assets::addCss('/assets/app.css');
Assets::addCss('/assets/print.css', ['media' => 'print']);
Assets::addCss(['/assets/a.css', '/assets/b.css']);  // несколько файлов
```

### `addJs($src, array $attrs = [], string $buffer = ''): void`

Добавляет JS-файл. При рендере — `<script src="...">`.

JS-файлы с атрибутом `type=module` **не объединяются** (независимо от конфига `combine`).

```php
Assets::addJs('/assets/app.js', ['defer' => true]);
Assets::addJs('/assets/widget.js', ['type' => 'module']);  // не будет combine
```

### `addFont($src, array $attrs = [], string $buffer = ''): void`

Добавляет шрифтовой файл.

```php
Assets::addFont('/fonts/Roboto-Regular.woff2');
```

### `addResource($path, array $attrs = [], string $buffer = ''): void`

Универсальный метод — тип ресурса определяется автоматически по MIME:

| MIME | Действие |
|---|---|
| `text/css` | → как `addCss` |
| `application/javascript` | → как `addJs` |
| `font/*` | → как `addFont` |
| `image/svg+xml` | → инлайн-вставка через `extract()` |
| Другой | Исключение `InvalidArgumentException` |

```php
Assets::addResource('/assets/app.css');
Assets::addResource('/icons/logo.svg');
```

### `addString($string, string $buffer = ''): void`

Добавляет произвольную HTML-строку в буфер без обработки. Полезно для `<meta>`, `<link rel="canonical">` и других тегов.

```php
Assets::addString('<meta name="robots" content="noindex">');
Assets::addString([
    '<meta name="description" content="...">',
    '<link rel="canonical" href="https://example.com/">',
]);
```

---

## 6. SVG и спрайты

### `svg(string $path, array $attrs = [], bool $return_path = false): string`

Возвращает инлайн-SVG разметку файла (через MIME-сервис `SvgMime`). ID-атрибуты внутри SVG уникализируются, что позволяет вставлять один файл несколько раз без конфликтов.

Если `$return_path = true` — возвращает относительный URL файла вместо инлайн-разметки.

```php
echo Assets::svg('/icons/logo.svg', ['class' => 'logo']);
// => <svg class="logo" ...>...</svg>

$url = Assets::svg('/icons/logo.svg', [], true);
// => '/icons/logo.svg'
```

Расширение `.svg` можно не указывать.

### Статический: `sprite(string $path, string $icon_id, array $attrs = []): string`
### Инстанс: `$a->sprite(string $icon_id, array $attrs = [], string $path = ''): string`

Возвращает `<svg><use href="...#icon_id">` для символа из SVG-спрайта.

В статическом вызове `$path` — первый аргумент и **обязателен**. В инстанс-вызове путь берётся из базового каталога инстанса; третьим аргументом можно переопределить.

```php
// Статический — path первым (как svg)
echo Assets::sprite('/img/sprite.svg', 'arrow-right', ['class' => 'icon']);

// Инстанс — icon_id первым, path не нужен (берётся базовый каталог)
$a = Assets::create(__DIR__);
echo $a->sprite('arrow-right', ['class' => 'icon']);

// Инстанс — явный путь к другому спрайту
echo $a->sprite('close', ['class' => 'icon'], 'img/other-sprite.svg');
```

---

## 7. Манифест и favicon

### `setManifest(array $params = [], string $buffer = ''): void`

Генерирует полный набор favicon-файлов из одного исходного изображения и добавляет в буфер соответствующие `<link>`-теги и `<meta name="theme-color">`. Создаёт `site.webmanifest`. Может быть вызван **только один раз** за запрос.

**Параметры:**

| Ключ | Тип | Описание |
|---|---|---|
| `favicon` | `string` | Путь к исходному изображению (svg/png) |
| `name` | `string` | Полное имя приложения (по умолчанию `$_SERVER['HTTP_HOST']`) |
| `short_name` | `string` | Короткое имя (по умолчанию = `name`) |
| `theme_color` | `string` | HEX-цвет темы (по умолчанию `#fff`) |
| `background_color` | `string` | HEX-цвет фона (по умолчанию `#fff`) |
| `display` | `string` | Режим отображения PWA (по умолчанию `standalone`) |

Генерируемые файлы: `favicon.svg`, `favicon-96x96.png`, `apple-touch-icon.png`, `web-app-manifest-192x192.png`, `web-app-manifest-512x512.png`, `favicon.ico`.

```php
// Статический
Assets::setManifest([
    'favicon'    => '/img/favicon.svg',
    'name'       => 'My App',
    'theme_color' => '#1a1a2e',
]);

// Инстанс (favicon разрешается относительно базового пути)
$a = Assets::create(__DIR__);
$a->setManifest([
    'favicon' => 'img/favicon.svg',
    'name'    => 'My App',
]);
```

---

## 8. Примеры использования (чистый PHP)

### Базовый шаблон

```php
<?php // header.php
Assets::mount('head');
?>
<html>
<head>
    <?php Assets::render('head'); ?>
</head>
<body>
```

```php
<?php // footer.php ?>
</body>
</html>
```

```php
<?php // page.php
Assets::setManifest(['favicon' => '/img/favicon.svg', 'name' => 'My Site']);
Assets::addCss(['/assets/reset.css', '/assets/app.css']);
Assets::addJs('/assets/app.js', ['defer' => true]);
Assets::addJs('/assets/widget.js', ['type' => 'module']);

include 'header.php';
// ... контент страницы
include 'footer.php';
```

### Два буфера (head + body)

```php
<?php // header.php
Assets::mount('head');
?>
<html><head><?php Assets::render('head'); ?></head>
<body>
<?php Assets::mount('body'); ?>
```

```php
<?php // footer.php ?>
<?php Assets::render('body'); ?>
</body></html>
```

```php
<?php // page.php
Assets::addCss('/assets/app.css');                   // -> буфер 'head' (default)
Assets::addJs('/assets/heavy.js', [], 'body');        // -> буфер 'body'
```

### Инстанс-режим в компоненте

```php
<?php // component/template.php
$a = Assets::create(__DIR__, 'head');
$a->addCss('css/component.css');
$a->addJs('js/component.js', ['defer' => true]);
echo $a->svg('icons/logo.svg', ['class' => 'logo']);
```

### Режим без буферизации

```php
<?php
Assets::config(['bufferization' => false]);
ob_start();
include 'page.php';
$html = ob_get_clean();
echo Assets::finalize($html);
```

```html
<!-- В шаблоне page.php -->
<head>
    <!-- Assets::mount("head") -->   <!-- ← плейсхолдер -->
</head>
```

---

## 9. Примеры использования в Bitrix

### header.php шаблона сайта

```php
<?php
// /local/templates/main/header.php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use ST_system\Assets;

Assets::mount('head');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title><?= $APPLICATION->GetTitle() ?></title>
    <?php Assets::render('head'); ?>
</head>
<body>
```

### footer.php шаблона сайта

```php
<?php
// /local/templates/main/footer.php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();
?>
</body>
</html>
```

### component_epilog.php компонента

```php
<?php
// /local/templates/main/components/bitrix/news.list/.default/component_epilog.php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use ST_system\Assets;

$a = Assets::create(__DIR__, 'head');
$a->addCss('style.css');
$a->addJs('script.js', ['defer' => true]);
```

### template.php компонента с инлайн-SVG и спрайтом

```php
<?php
// /local/templates/main/components/bitrix/main.include/.default/template.php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use ST_system\Assets;

$a = Assets::create(__DIR__, 'head');
$a->addCss('style.css');
?>
<header class="header">
    <?= $a->svg('img/logo.svg', ['class' => 'logo']) ?>
    <nav>
        <button><?= $a->sprite('menu', ['class' => 'icon']) ?></button>
    </nav>
</header>
```

### Манифест в init.php или в header.php

```php
<?php
// /local/templates/main/header.php (фрагмент)

use ST_system\Assets;

Assets::mount('head');

$a = Assets::create($_SERVER['DOCUMENT_ROOT'].'/local/templates/main');
$a->setManifest([
    'favicon'          => 'img/favicon.svg',
    'name'             => 'My Bitrix Site',
    'theme_color'      => '#ffffff',
    'background_color' => '#ffffff',
]);
```

### Добавление ресурсов из нескольких компонентов

Благодаря дедупликации один и тот же файл не выведется дважды, даже если несколько компонентов на странице добавляют его:

```php
<?php
// component A
$a = Assets::create(__DIR__);
$a->addCss('../../shared/grid.css');  // добавится один раз

// component B
$b = Assets::create(__DIR__);
$b->addCss('../../shared/grid.css');  // будет проигнорировано — уже есть в буфере
```
