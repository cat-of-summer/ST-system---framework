<!-- DOCGEN:START -->
# XmlMime.php
<!-- DOCGEN:END -->

`ST_system\Storage\Mimes\XmlMime` — MIME-обработчик для контента типа `application/xml`/`text/xml`. Наследует `Mime` (см. `docs/src/Storage/Mimes/Mime.php.md`) и подключает трейт `Storage\Mimes\Traits\Extractable` (см. `docs/src/Storage/Mimes/Traits/Extractable.php.md`) для DOM/xpath-извлечения структурированных данных из XML. Аналог `HtmlMime`, только для XML вместо HTML.

## Публичные методы

- **`get($data)`** — тождественное преобразование: `(string)$data`. В отличие от `JsonMime`, сам XML не парсится на этом шаге — разбор происходит лениво через `Extractable` (`getDom()`/`getXPath()`/`extract()`).
- **`toArray(): array`** — полностью разворачивает XML-документ в вложенный PHP-массив: корневой элемент документа (`getDom()->documentElement`, из трейта `Extractable`) рекурсивно обходится приватным хелпером `domNodeToArray()`. Возвращает одноэлементный массив `[имя_корневого_узла => структура]`.

### Формат `toArray()` / `domNodeToArray()`

Для каждого XML-узла:
- атрибуты попадают под ключ `@attributes` (`['имя' => 'значение', ...]`);
- дочерние элементы с одинаковым именем на одном уровне схлопываются в массив-список (первое вхождение — просто значение, при повторном имени существующее значение оборачивается в массив и в него добавляется новое);
- текстовое содержимое узла (включая CDATA) попадает под ключ `@text`, если у узла одновременно есть и текст, и дочерние элементы/атрибуты; если текст — единственное содержимое, возвращается просто строка.

## `loadDom()` (protected, для `Extractable`)

`Extractable::getDom()` делегирует парсинг сюда: строит `\DOMDocument` (`preserveWhiteSpace = false`), грузит XML через `loadXML(..., LIBXML_NOCDATA | LIBXML_NONET)`. На ошибке парсинга (невалидный XML или пустой `documentElement`) бросает `\Exception` с текстом всех накопленных `libxml`-ошибок.

## Пример использования (через `File`/`Resource`)

```php
$file = \ST_system\Storage\File::make('feed.xml');

$array = $file->toArray();          // полный вложенный массив документа

$title = $file->extract([           // точечное извлечение по xpath-схеме (см. Extractable)
    'title' => ['@xpath' => '//channel/title', '@array' => false],
]);
// ['title' => '...']
```
