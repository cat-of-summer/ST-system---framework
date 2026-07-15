<!-- DOCGEN:START -->
# Traits

## Файлы

- [Combinable.php](Combinable.php.md)
- [Extractable.php](Extractable.php.md)
- [Minifiable.php](Minifiable.php.md)

<!-- DOCGEN:END -->

## Обзор

Трейты этой директории — переиспользуемое поведение, которое подмешивается в конкретные Mime-обработчики (`src/Storage/Mimes/*Mime.php`), чтобы не дублировать однотипную логику (кеширование, поиск/валидация файлов, работа с DOM) в каждом из них.

- **[Combinable.php](Combinable.php.md)** — объединение (конкатенация) нескольких файлов-ассетов в один с кешированием результата по mtime исходников. Используют: `CssMime`, `JsMime`, `SvgMime`, `FontMime`.
- **[Extractable.php](Extractable.php.md)** — извлечение структурированных данных из DOM-тела (HTML/XML) по xpath-схеме, с кешированием по идентификатору ресурса и хешу схемы. Используют: `HtmlMime`, `XmlMime`.
- **[Minifiable.php](Minifiable.php.md)** — минификация содержимого файла с кешированием результата, инвалидируемым по mtime-штампу. Используют: `CssMime`, `JsMime`, `SvgMime`, `FontMime`.

Каждый трейт задаёт общий "каркас" (поиск исходных файлов, работу с кешем, обход DOM и т.п.) и делегирует специфичную для формата часть абстрактным методам, которые реализует конкретный Mime-класс (например, `__combine()`, `__minify()`, `loadDom()`).
