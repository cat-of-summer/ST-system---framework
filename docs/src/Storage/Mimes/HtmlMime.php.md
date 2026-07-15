<!-- DOCGEN:START -->
# HtmlMime.php
<!-- DOCGEN:END -->

## Назначение

`HtmlMime` — обработчик MIME `text/html`, зарегистрированный для файлов `.html`/`.htm`. Подмешивает трейт `ST_system\Storage\Mimes\Traits\Extractable`, который даёт общий движок построения DOM и выборки данных по xpath-схеме (`getDom()`, `getXPath()`, `extract($schema, $data)`); `HtmlMime` реализует для него только специфичную для HTML часть — загрузку DOM через `loadHTML`.

## Публичные методы

- `get($data)` — возвращает содержимое как строку (`(string)$data`); полноценный разбор/выборка для HTML выполняется через `getDom()`/`extract()` из `Extractable`, а не через `get()`.
- `purge(bool $storage = true): void` — сбрасывает построенный DOM/XPath (`purgeDom()` из `Extractable`) и делегирует в `parent::purge()`.

## Protected-контракт для `Extractable`

- `loadDom(string $html): \DOMDocument` — создаёт `\DOMDocument`, подавляет предупреждения libxml (`libxml_use_internal_errors(true)`) и загружает HTML через `mb_encode_numericentity()` (кодирует не-ASCII символы числовыми сущностями перед `loadHTML`, чтобы избежать повреждения многобайтных символов, так как `DOMDocument::loadHTML` по умолчанию не понимает UTF-8 без BOM/meta).

## Примеры вызова

```php
// Текстовое содержимое как есть
$html = $file->getContents();

// Выборка данных по xpath-схеме (метод даёт трейт Extractable)
$data = $file->extract([
    'title' => '//h1',
    'links' => ['@xpath' => '//a', '@extract' => fn($node) => $node->getAttribute('href')],
]);

// Прямой доступ к DOM/XPath, если схемы недостаточно
$dom = $file->getDom();
```

Подробности механизма выборки (кеширование по `mtime`/хешу тела, формат схемы `@xpath`/`@extract`/`@array`) — в документации трейта `Extractable` (`docs/src/Storage/Mimes/Traits/`).
