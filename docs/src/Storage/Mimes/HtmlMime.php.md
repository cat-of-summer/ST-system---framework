# HtmlMime.php

## 1. Концепция

MIME-сервис для `text/html`. Разбирает документ в `DOMDocument`, даёт `DOMXPath` и извлекает данные по декларативной схеме. Разобранный DOM и XPath держатся в объекте и переиспользуются.

Результат `extract()` кэшируется навсегда (TTL=-1) в файле `<md5(схема+данные)>.json` и инвалидируется по штампу `mtime` исходника.

```php
$page = File::make('https://example.com/');

$data = $page->extract([
    'title'   => '//h1',                                  // строка = @xpath
    'links'   => ['@xpath' => '//a/@href'],
    'summary' => ['@xpath' => '//p[1]', '@array' => false],
]);
// ['title' => ['…'], 'links' => [...], 'summary' => '…']
```

Для URI сервис сначала вызывает `fetch()`, поэтому кэш ключуется по исходному URL, а штамп берётся с уже скачанного файла.

## 2. Схема извлечения

Ключ схемы — имя поля в результате. Значение — либо строка XPath, либо массив с директивами:

| Директива | По умолчанию | Описание |
|---|---|---|
| `@xpath` | — | XPath-селектор. Обязателен. |
| `@array` | `true` | `true` — массив всех совпадений, `false` — первое совпадение или `null` |
| `@extract` | `null` | `null` — текст узла; `callable` — `fn(DOMNode $n, array $data)`; `array` — вложенная схема |

Селектор по умолчанию вычисляется относительно текущего узла. Префикс `~` делает его глобальным (от корня документа). Ключи схемы, начинающиеся с `@`, пропускаются.

```php
$rows = $page->extract([
    'items' => [
        '@xpath'   => '//table//tr',
        '@extract' => [                       // вложенная схема, контекст = <tr>
            'name'  => ['@xpath' => 'td[1]', '@array' => false],
            'price' => [
                '@xpath'   => 'td[2]',
                '@array'   => false,
                '@extract' => fn($node) => (int)filter_var($node->nodeValue, FILTER_SANITIZE_NUMBER_INT),
            ],
        ],
    ],
]);
```

> Замыкания в `@extract` участвуют в ключе кэша через `Main::hash()`, который кодирует их по позиции в исходниках и захваченным `use`-переменным. Одно и то же замыкание даёт один и тот же ключ между запусками.

## 3. Публичные методы

### `extract(array $schema, array $data = []): array`
Применяет схему к документу. `$data` передаётся в `@extract`-замыкания. Результат кэшируется; пересчитывается при изменении `mtime` исходника.

### `getDom(): \DOMDocument`
Ленивый `DOMDocument`. HTML разбирается с подавлением ошибок libxml, вход перекодируется в HTML-entities, чтобы не потерять UTF-8.

### `getXPath(): \DOMXPath`
Ленивый `DOMXPath` поверх `getDom()`.

### `get(mixed $data): \DOMDocument`
Переопределение `Mime::get()`: `$file->getContents()` для HTML возвращает `DOMDocument`, а не строку. Сырой текст — через `getRaw()`.

### `purge(bool $storage = true): void`
Сбрасывает `$dom` и `$xpath`, затем — кэш `extract()` (при `$storage = true` ещё и удаляет его с диска).
