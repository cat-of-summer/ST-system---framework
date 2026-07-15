<!-- DOCGEN:START -->
# Mimes

## Папки

- [Traits](Traits/)

## Файлы

- [CssMime.php](CssMime.php.md)
- [FontMime.php](FontMime.php.md)
- [HtmlMime.php](HtmlMime.php.md)
- [ImageMime.php](ImageMime.php.md)
- [JsMime.php](JsMime.php.md)
- [JsonMime.php](JsonMime.php.md)
- [Mime.php](Mime.php.md)
- [SvgMime.php](SvgMime.php.md)
- [TextPlainMime.php](TextPlainMime.php.md)
- [XmlMime.php](XmlMime.php.md)

<!-- DOCGEN:END -->

Семейство MIME-обработчиков контента, все наследуют `Mime` (общий контракт `get()`/`set()`/`purge()`/`toHTML()` — см. `Mime.php.md`). Подбираются и инстанцируются автоматически классами `Storage\Resource`/`Storage\File` по фактическому MIME-типу файла — код-потребитель почти никогда не создаёт эти классы напрямую, а вызывает их методы через `$file->...()` (проксируется через `Resource::__call()`).

- **`CssMime`**, **`JsMime`** — CSS/JS-ассеты; подключают `Combinable` (объединение файлов) и `Minifiable` (минификация).
- **`SvgMime`** — SVG-ассеты; тоже `Combinable`+`Minifiable`, плюс инлайнинг с переписыванием `id` и генерация/чтение спрайтов.
- **`FontMime`** — веб-шрифты.
- **`ImageMime`** — растровые изображения (конвертация форматов, генерация вариантов размеров).
- **`HtmlMime`**, **`XmlMime`** — HTML/XML-контент; оба подключают `Extractable` (DOM/xpath-извлечение структурированных данных).
- **`JsonMime`** — JSON-контент (`get()`/`set()` = decode/encode).
- **`TextPlainMime`** — обычный текст, самый минимальный обработчик (без преобразований).

Общее переиспользуемое поведение (`Combinable`, `Minifiable`, `Extractable`) вынесено в трейты в поддиректории `Traits/` — см. её README.
