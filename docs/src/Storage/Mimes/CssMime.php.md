<!-- DOCGEN:START -->
# CssMime.php
<!-- DOCGEN:END -->

## Назначение

`CssMime` — обработчик MIME `text/css`. Регистрируется в `Resource::config('mimes.services')` для типа `text/css` и подключается автоматически ко всем `File`/`Resource` с этим MIME (например, файлы `.css`).

Подмешивает трейты:
- `ST_system\Storage\Mimes\Traits\Minifiable` — даёт метод `minify()`: минифицированная копия файла с кешированием по mtime исходника.
- `ST_system\Storage\Mimes\Traits\Combinable` — даёт метод `combine()`: объединение нескольких CSS-файлов в один кешированный файл.

## Публичные методы

- `toHTML(array $config = []): string` — рендерит `<link rel="stylesheet" ...>`. Поддерживает `$config['type']` (по умолчанию `text/css`) и `$config['media']` (атрибут `media`).
- `__minify(string $content, array $config): string` (static, вызывается трейтом `Minifiable`) — упрощённая минификация CSS: удаление комментариев `/* ... */`, схлопывание пробелов вокруг `{ } ; : > , + ~`, схлопывание повторяющихся пробелов, удаление лишней `;` перед `}`.

## Protected-контракт для трейтов

- `__combine(array $files, array $config): string` — реализация для `Combinable`: конкатенирует HTML-рендер (`toHTML()`) каждого файла, вырезая обёртку `<style>...</style>`, если она присутствует.
- `__combineExtension(): string` — возвращает `'css'` (расширение результирующего комбинированного файла).

## Примеры вызова

```php
// Подключить стиль в разметку
echo $file->toHTML(['media' => 'screen']);

// Получить минифицированную версию (кешируется по mtime)
$min = $file->minify();

// Склеить несколько CSS-файлов в один
$bundle = $file->combine(['app.css', 'vendor.css']);
```

Все вызовы идут через `File`/`Resource` — `Resource::__call()` резолвит вызов в `CssMime`, так как этот класс зарегистрирован под MIME `text/css`.
