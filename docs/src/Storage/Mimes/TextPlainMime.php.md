<!-- DOCGEN:START -->
# TextPlainMime.php
<!-- DOCGEN:END -->

`ST_system\Storage\Mimes\TextPlainMime` — MIME-обработчик для контента типа `text/plain`. Наследует `Mime` (см. `docs/src/Storage/Mimes/Mime.php.md`). Самый минимальный обработчик в семействе — не переопределяет ни `get()`, ни `set()` (используются тождественные реализации родителя), только `toHTML()`.

## Публичные методы

- **`toHTML(array $config = []): string`** — возвращает содержимое файла как есть (`$this->file->getContents()`), без какого-либо HTML-оборачивания и без экранирования.

## Пример использования (через `File`/`Resource`)

```php
$file = \ST_system\Storage\File::make('notes.txt');

echo $file->toHTML(); // содержимое файла напрямую
$text = $file->get(); // тождественно $file->getRaw() — преобразований нет
```

Если текст предполагается вставлять в HTML-разметку, экранирование (`htmlspecialchars`) нужно делать на стороне вызывающего кода — `TextPlainMime` этого не делает.
