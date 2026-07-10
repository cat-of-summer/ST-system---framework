# `ST_system\View` — шаблонизатор

Небольшой гибкий шаблонизатор на чистом PHP, по механике похожий на Astro: layout
оборачивает страницу через слот, компоненты получают пропсы, данные текут вниз по дереву
рендера. Кеша нет — шаблоны выполняются на каждый запрос.

- Namespace: `ST_system\View` (PSR-4, `ST_system\ => src/`).
- Требует PHP ≥ 7.4. Шаблоны — обычные `.php`-файлы с `<?= ?>` / `<? ?>`.
- Шаблоны зовут голый `View::` — для этого в `init.php` заведён алиас
  `class_alias(ST_system\View::class, 'View')`.

---

## Быстрый старт

**1. Настроить источники (`init.php`):**

```php
use ST_system\View;

class_alias(View::class, 'View'); // чтобы шаблоны звали голый View::

View::setConfig([
    'source' => [
        'layouts'    => '~/storage/layouts',
        'components' => ['source' => '~/storage/components', 'alias' => 'component'],
        'pages'      => ['source' => '~/storage/pages',      'alias' => 'page'],
    ],
]);
```

**2. Написать шаблоны:**

```php
// storage/pages/index.php
View::layouts('main', function () {
    View::component('header');
    ?>
    <main>Привет, <?= htmlspecialchars(View::get('name', 'мир')) ?>!</main>
    <?php
    View::component('footer');
});
```

```php
// storage/layouts/main.php
<!doctype html>
<html lang="ru">
    <? View::slot(['head.title' => 'Заголовок по умолчанию']); ?>
</html>
```

```php
// storage/components/header.php
<head>
    <title><?= htmlspecialchars(View::get('head.title')) ?></title>
</head>
<body>
```

```php
// storage/components/footer.php
</body>
```

**3. Отрендерить (в контроллере):**

```php
return Response::html(
    View::page('index', ['name' => 'Аня'])->toHtml()
);
```

---

## Конфигурация

`View::setConfig([...])` (метод из трейта `HasConfig`). Ключи:

| Ключ | По умолчанию | Назначение |
|---|---|---|
| `source` | `[]` | Строка или массив источников (см. ниже) |
| `extension` | `'php'` | Расширение файлов шаблонов |
| `exclude` | `[]` | Имена папок, которые пропустить при автодискавери |

### `source` строкой — автодискавери

```php
View::setConfig(['source' => '~/storage/views']);
```

Каждая папка верхнего уровня становится «методом»: `views/layouts`, `views/pages`,
`views/components` → `View::layouts()`, `View::pages()`, `View::components()`. Имя метода —
ровно имя папки (basename). Ненужные папки исключаются:

```php
View::setConfig(['source' => '~/storage', 'exclude' => ['jobs']]);
```

### `source` массивом — явные имена и алиасы

```php
View::setConfig([
    'source' => [
        'layouts'    => '~/storage/layouts',                                   // → View::layouts()
        'components' => ['source' => '~/storage/components', 'alias' => 'component'], // → View::components() и View::component()
        'pages'      => ['source' => '~/storage/pages',      'alias' => 'page'],      // → View::pages() и View::page()
    ],
]);
```

Ключ регистрирует основное имя, `alias` — дополнительное имя на тот же каталог. Так
получаются удобные сингуляры `View::page()` / `View::component()`.

> `~/` в путях разворачивается в корень документа (`DOCUMENT_ROOT`, иначе `COMPOSER_ROOT`).

---

## Резолв путей

`View::page('about')` ищет по порядку и берёт первый существующий файл:

1. `<source>/about.php`
2. `<source>/about/index.php`

Вложенность через `/`: `View::page('blog/post')` → `<source>/blog/post.php` (или
`.../blog/post/index.php`). Если ни один не найден — `RuntimeException`.

Сегменты `.`, `..` и пустые запрещены — `View::page('../../etc/passwd')` бросит
`InvalidArgumentException` (защита от выхода за пределы каталога источника).

---

## Модель рендера

Каждый вызов вида пушит **кадр** в стек рендера. `View::page('index', $props)->toHtml()`:

```
toHtml()                          стек: []
 └ page:index    (storage/pages/index.php)        [index]
    └ layouts:main  (storage/layouts/main.php)     [index, main]
       └ slot() → children()  (замыкание страницы)
          ├ component:header                        [index, main, header]
          ├ ...тело main...
          └ component:footer                        [index, main, footer]
```

Замыкание страницы (`children`) исполняется **лениво — внутри `slot()`**, уже когда layout
рендерится. Поэтому компонент, вложенный в children (например `header`), видит значения,
которые layout только что подставил дефолтом через `slot([...])`.

Ограничение глубины — 50 кадров (самоссылающийся компонент падает внятным исключением, а не
переполнением стека).

---

## Возврат и вывод

Любая фабрика (`page`/`layouts`/`component`/…) возвращает объект `View`. Как он выводится,
зависит от того, вложенный вызов или корневой:

- **Вложенный** вызов (внутри другого рендера) печатает себя сам — отложенно, при уничтожении
  объекта.
- **Корневой** объект ленивый: пока не позвали `render()` / `toHtml()` / не привели к строке —
  ничего не выводится.

```php
// внутри шаблона (вложенный контекст):
View::component('header');                    // временный объект → печатает на ';'
$html = View::component('card')->toHtml();    // строкой, НЕ печатает — можно обработать
echo strtoupper($html);
View::component('footer')->render();          // печатает явно
<?= View::component('aside') ?>               // __toString() → печатает один раз
```

**Важное правило:** временный объект печатается на `;`, а объект, присвоенный в переменную, —
когда переменная умрёт. Поэтому

```php
$h = View::component('header'); ?><div>…</div><?php
// header выведется ПОСЛЕ <div>, когда $h выйдет из области видимости
```

Если присвоил вид в переменную — заверши его явным `->render()` или `->toHtml()`.

### Методы вывода

| Метод | Что делает |
|---|---|
| `->toHtml(): string` | Возвращает HTML строкой, ничего не печатает. На корне прогоняет `Assets::finalize()`. Повторный вызов вернёт `''`. |
| `->render(): void` | Печатает (`echo`) здесь и сейчас. Повторный вызов — no-op. |
| `(string)$view` | То же, что `toHtml()`. |

> **В контроллере всегда `toHtml()`, не `render()`.** Фронт-контроллер оборачивает запрос в
> выходной буфер и чистит его перед отправкой ответа — вывод от `render()` был бы стёрт.
> `render()` предназначен для использования внутри шаблонов.

---

## Пропсы и данные

Данные передаются вторым аргументом фабрики и читаются через `View::get()`.

```php
View::component('header', ['head' => ['title' => 'Главная']]);
// внутри header.php:
View::get('head.title');   // 'Главная'
```

### Точечная нотация

Пропсы нормализуются: вложенный массив и плоский точечный ключ эквивалентны.

```php
['head' => ['title' => 'X']]   // ⇔   ['head.title' => 'X']
// оба дают:
View::get('head.title'); // 'X'
View::get('head');       // ['title' => 'X']
```

### `View::get(string $key, $default = null)`

Ищет по стеку кадров **от текущего вверх к родителям**, затем в глобальном бэге, затем
возвращает `$default`. Ближний кадр выигрывает:

```
View::page('index', ['title' => 'Главная'])
  └ layouts('main')                       // кадр без title
     └ component('header', ['x' => 1])     // кадр с x
        // внутри header.php:
        View::get('x')            → header ✓ → 1
        View::get('title')        → header ✗ → main ✗ → index ✓ → 'Главная'
        View::get('missing', '—') → всё ✗ → globals ✗ → '—'
```

> `get()` возвращает **сырое** значение. Экранирование — на совести шаблона:
> `<?= htmlspecialchars(View::get('title'), ENT_QUOTES, 'UTF-8') ?>`.

### `View::set($key, $value = null)` — глобальные значения

Пишет в глобальный бэг (низший приоритет при поиске). Виден всем видам всех запросов… то
есть в пределах запроса. Принимает строку+значение или массив:

```php
View::set('brand', 'Whisper');
View::set(['asset' => ['css' => '/public/app.css', 'js' => '/public/app.js']]);

View::get('asset.css'); // '/public/app.css' из любого шаблона
```

Удобно для общих значений (пути к ассетам, имя сайта), которые не хочется прокидывать через
каждый вызов.

---

## `View::name(int $i = 0)` — имя вида по уровню вложенности

Возвращает короткое имя вида в стеке рендера. Полезно общим компонентам, которым надо знать,
«где они» (выбрать бандл ассетов, подсветить активный пункт меню и т.п.).

```
стек: [page:index, layouts:main, component:header]

View::name()    // = name(0) → 'index'   корень (страница)
View::name(1)   //           → 'main'    следующий уровень
View::name(2)   //           → 'header'
View::name(-1)  //           → 'header'  текущий (самый глубокий)
View::name(9)   //           → ''        вне диапазона
```

- `$i = 0` — корень рендера (в типичном потоке это страница).
- Положительный — вглубь от корня.
- Отрицательный — от текущего вида (`-1` = тот, что рендерится сейчас).
- Выход за границы стека — пустая строка.

Пример: общий `header.php` сам выбирает бандл по имени страницы, без прокидывания:

```php
<?php $page = View::name(); ?>
<link rel="stylesheet" href="<?= htmlspecialchars(View::get("asset.{$page}_css")) ?>">
```

---

## Слоты

`View::layouts('main', $children)` рендерит содержимое страницы **внутрь** layout. Точка
вставки в layout помечается `View::slot()`.

### Дефолтный слот + fill-семантика

```php
// pages/index.php
View::layouts('main', function () {
    ?><main>Контент</main><?php
});

// layouts/main.php
<!doctype html>
<html>
    <? View::slot(['head.title' => 'Заголовок по умолчанию']); ?>
</html>
```

`View::slot($defaults)` делает две вещи по порядку:

1. **Ставит дефолты** — но только для ключей, которых нет нигде выше по стеку и в globals
   (семантика fill, не override). Значит пропс из `View::page('index', ['head.title' => 'X'])`
   **побеждает** дефолт layout.
2. **Рендерит children** (замыкание страницы) в этой точке.

```php
View::page('index', ['head.title' => 'Главная'])
// → внутри вложенного header: 'Главная' (пропс перебил дефолт)

View::page('index')
// → 'Заголовок по умолчанию' (сработал дефолт из slot)
```

### Именованные слоты

Children может быть массивом замыканий `['default' => fn, 'name' => fn, …]`:

```php
// pages/index.php
View::layouts('main', [], [
    'default' => function () { ?><main>основной контент</main><?php },
    'aside'   => function () { ?><aside>сайдбар</aside><?php },
]);

// layouts/main.php
<div class="content"><? View::slot(); ?></div>
<div class="sidebar"><? View::slot('aside'); ?></div>
```

`View::slot()` — дефолтный слот, `View::slot('aside')` — именованный. Дефолты можно ставить и
для именованного: `View::slot('aside', ['title' => '…'])`.

### Формы вызова `slot()`

| Форма | Что делает |
|---|---|
| `slot()` | Рендерит дефолтный слот |
| `slot(array $defaults)` | Ставит дефолты + рендерит дефолтный слот |
| `slot(string $name)` | Рендерит именованный слот |
| `slot(string $name, array $defaults)` | Дефолты + именованный слот |

---

## Компоненты

`View::component('header', $props)` подключает переиспользуемый кусок. Вложенность через `/`:
`View::component('ui/button')`.

```php
View::component('header', ['head' => ['title' => 'Главная']]);
```

Внутри компонента доступны `View::get()`, `View::name()`, `View::component()` (вложенные
компоненты), и переменная `$props` — сырой массив пропсов текущего кадра (запасной люк; в
основном пользуйтесь `View::get()`).

---

## `View::capture(callable $fn): string`

Захватывает произвольный вывод в строку — когда нужно собрать несколько печатающих вызовов
разом:

```php
$html = View::capture(function () {
    View::component('a');
    View::component('b');
});
```

`capture()` не трогает стек кадров, поэтому `View::get()` внутри видит те же кадры, что и
снаружи.

---

## Интеграция с `ST_system\Assets`

`View` владеет выводом, поэтому `Assets` работает в **строковом** режиме. В `init.php`:

```php
use ST_system\Assets;
Assets::setConfig(['bufferization' => false]);
```

Дальше:

- в шаблоне, где ассеты должны появиться, ставится точка сборки: `Assets::mount('head')`
  (печатает плейсхолдер);
- любой (в т.ч. глубоко вложенный) вид добавляет ресурсы: `Assets::addString(...)`,
  `Assets::addCss(...)`, `Assets::addJs(...)` в буфер `'head'`;
- корневой `toHtml()` автоматически прогоняет `Assets::finalize()` — плейсхолдер заменяется
  накопленным содержимым.

Так вложенный компонент дотягивается до `<head>`:

```php
// pages/index.php — задаём инлайн-скрипт в <head> из страницы
Assets::addString('<script>window.WS_URL = "ws://...";</script>', 'head');

// components/header.php — точка вставки
<head>
    ...
    <? Assets::mount('head'); ?>
</head>
```

> `Assets::finalize()` вызывается только на корне (когда стек кадров снова пуст). Вложенный
> `toHtml()` его не запускает, поэтому плейсхолдер не «съедается» раньше времени.

---

## Полный пример

```php
// init.php
class_alias(ST_system\View::class, 'View');
View::setConfig([
    'source' => [
        'layouts'    => '~/storage/layouts',
        'components' => ['source' => '~/storage/components', 'alias' => 'component'],
        'pages'      => ['source' => '~/storage/pages',      'alias' => 'page'],
    ],
]);
View::set(['asset' => ['index_css' => '/public/app.css?v=1']]);
```

```php
// Api/Controllers/PageController.php
use ST_system\View;
use ST_system\HTTP\Response;

public function index(): Response {
    return Response::html(
        View::page('index', ['head' => ['title' => 'Главная']])->toHtml()
    );
}
```

```php
// storage/pages/index.php
View::layouts('main', function () {
    View::component('header');
    ?>
    <main>
        <h1><?= htmlspecialchars(View::get('head.title')) ?></h1>
    </main>
    <?php
    View::component('footer');
});
```

```php
// storage/layouts/main.php
<!doctype html>
<html lang="ru">
    <? View::slot(['head.title' => 'Сайт']); ?>
</html>
```

```php
// storage/components/header.php
<?php $page = View::name(); ?>
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars(View::get('head.title')) ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars(View::get("asset.{$page}_css")) ?>">
    <? \ST_system\Assets::mount('head'); ?>
</head>
<body>
```

```php
// storage/components/footer.php
</body>
```

---

## Справочник публичного API

### Конфигурация (из `HasConfig`)

| Метод | Описание |
|---|---|
| `View::setConfig(array $config): void` | Задать `source` / `extension` / `exclude` |
| `View::config(string $key = '')` | Прочитать конфиг по точечному ключу |

### Фабрики видов (динамические имена из `source`)

| Вызов | Возвращает |
|---|---|
| `View::page(string $name, array $props = [], $children = null): View` | Вид страницы |
| `View::layouts(string $name, callable $children): View` | Layout (шорткат: 2-й арг — замыкание) |
| `View::layouts(string $name, array $props, callable\|array $children): View` | Layout с пропсами и/или именованными слотами |
| `View::component(string $name, array $props = []): View` | Компонент |

> Имена методов (`page`, `layouts`, `component`, …) берутся из `source`/`alias`. Второй
> аргумент считается `children`, если он **не массив** (замыкание или карта замыканий);
> иначе это пропсы, а `children` — третий аргумент.

### Статические помощники

| Метод | Описание |
|---|---|
| `View::get(string $key, $default = null)` | Сырое значение по точечному ключу: кадр → родители → globals → default |
| `View::set($key, $value = null): void` | Записать в globals (строка+значение или массив) |
| `View::name(int $i = 0): string` | Имя вида по уровню вложенности (0 — корень, отрицательный — от текущего) |
| `View::slot(...$args): void` | Отрендерить слот (+ поставить дефолты); формы см. выше |
| `View::capture(callable $fn): string` | Захватить вывод замыкания в строку |

### Методы экземпляра

| Метод | Описание |
|---|---|
| `->toHtml(): string` | HTML строкой; на корне финализирует Assets |
| `->render(): void` | Печатает сейчас |
| `->__toString(): string` | То же, что `toHtml()` |

### Зарезервированные имена

Источник (папка/алиас) не может называться так же, как метод: `get`, `set`, `slot`,
`capture`, `config`, `setConfig`, `applyConfig`, `sources`, `name`, `render`, `toHtml` —
иначе `LogicException` при резолве источников.

---

## Подводные камни

- **Контроллер → `toHtml()`, не `render()`** — иначе вывод сотрёт выходной буфер
  фронт-контроллера.
- **Вид в переменной печатается при её уничтожении.** Присвоил — заверши явным
  `render()`/`toHtml()`, иначе порядок вывода удивит.
- **`get()` не экранирует.** Экранируйте в шаблоне (`htmlspecialchars`), особенно
  пользовательский ввод. Готовый HTML (таблицы, инлайн-скрипты) выводится как есть.
- **`slot([...])` ставит дефолты, а не перезаписывает** — пропсы страницы всегда сильнее
  дефолтов layout.
- **Кеша нет** — шаблоны выполняются на каждый запрос.
