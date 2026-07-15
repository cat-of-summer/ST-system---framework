<!-- DOCGEN:START -->
# JsMime.php
<!-- DOCGEN:END -->

## Назначение

`JsMime` — обработчик MIME `text/javascript`/`application/javascript`. Подмешивает трейты:
- `ST_system\Storage\Mimes\Traits\Minifiable` — метод `minify()` с кешированием по mtime.
- `ST_system\Storage\Mimes\Traits\Combinable` — метод `combine()` для склейки нескольких JS-файлов.

Помимо HTML-рендера и контрактов трейтов, класс содержит собственный встроенный JS-минификатор (посимвольный разбор с состояниями `a`/`b`/`c`, аналогичный алгоритму JShrink) — отдельного класса под минификатор в проекте нет, вся логика лежит прямо в `JsMime`.

## Публичные методы

- `toHTML(array $config = []): string` — рендерит `<script src="..." type="..." ...>`. Поддерживает `$config['type']` (по умолчанию `text/javascript`), `$config['async']` и `$config['defer']` (булевы флаги атрибутов).
- `__minify(string $content, array $config): string` — точка входа минификации, вызываемая трейтом `Minifiable`. Блокирует потенциально проблемные последовательности (`lock()`), прогоняет посимвольный минификатор (`minifyToString()`), затем восстанавливает заблокированные участки (`unlock()`); при ошибке чистит внутреннее состояние (`clean()`) и пробрасывает исключение дальше.

## Protected-контракт для трейтов

- `__combine(array $files, array $config): string` — конкатенирует исходный код файлов (`getRaw()`) через `;\n`.
- `__combineExtension(): string` — возвращает `'js'`.

## Внутренний минификатор

Остальные protected-методы (`minifyToString`, `initialize`, `loop`, `clean`, `getChar`, `peek`, `getReal`, `processOneLineComments`, `processMultiLineComments`, `getNext`, `saveString`, `saveRegex`, `isAlphaNumeric`, `endsInKeyword`, `lock`, `unlock`) и связанные protected-свойства (`$input`, `$len`, `$index`, `$a`, `$b`, `$c`, `$output`, `$options`, `$locks`, `$stringDelimiters`, `$noNewLineCharacters`, `static $defaultOptions`, `static $keywords`) реализуют сам алгоритм минификации: удаление комментариев (с сохранением `/*!`- и `/*@`-помеченных), схлопывание незначащих пробелов/переводов строк с учётом контекста (ключевые слова, регулярные выражения, строки), при этом строковые литералы и regex-паттерны не изменяются. Это деталь реализации `__minify()` и напрямую извне не используется.

## Примеры вызова

```php
// Подключить скрипт в разметку
echo $file->toHTML(['defer' => true]);

// Минифицированная версия (кешируется по mtime)
$min = $file->minify();

// Склеить несколько JS-файлов в один бандл
$bundle = $file->combine(['a.js', 'b.js']);
```
