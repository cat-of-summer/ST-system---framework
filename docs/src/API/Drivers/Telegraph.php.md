<!-- DOCGEN:START -->
# Telegraph.php
<!-- DOCGEN:END -->

`final class Telegraph extends IntegrationDriver` (`ST_system\API\Drivers\Telegraph`) — драйвер для API публикации [Telegra.ph](https://telegra.ph) (`https://api.telegra.ph`). Наследует [`IntegrationDriver`](../IntegrationDriver.php.md); собственная специфика — автоматическое создание/кеширование аккаунта Telegraph при первом обращении и конвертация HTML/DOM в формат `Node[]`, который ожидает Telegraph API для содержимого страниц.

## Создание инстанса

```php
$telegraph = Telegraph::create([
    'short_name'  => 'MyBlog',                 // обязателен — короткое имя аккаунта Telegraph
    'author_name' => 'Иван Иванов',            // опционально
    'author_url'  => 'https://example.com',    // опционально, по умолчанию — определяемый base_url
    'base_url'    => 'https://example.com',    // опционально; если не задан — определяется из $_SERVER (HTTPS + HTTP_HOST)
]);
```

При создании: если у инстанса включён и валиден instance-level кеш (`$this->cache()->isValid()`), `access_token` берётся из кеша; иначе выполняется реальный вызов `createAccount` с переданными `short_name`/`author_name`/`author_url`, и полученный `access_token` сохраняется в кеш для последующих запусков. Таким образом аккаунт Telegraph создаётся **один раз** на комбинацию аргументов конструктора (кеш инстанса ключуется, среди прочего, аргументами `__construct`, как описано в `IntegrationDriver`), а не при каждом создании инстанса.

> Внутри конструктора используются вызовы `$this->cacheGet()` / `$this->cacheSet($token)` — обёрточных методов с такими именами нет ни в `Telegraph`, ни в базовом `IntegrationDriver` (там есть только `cache(): ?CacheManager`, а получение/запись значения — методы `CacheManager::get()`/`set()`). Это существующая особенность файла — при вызове ветки `cacheGet()`/`cacheSet()` в рантайме будет фатальная ошибка "Call to undefined method"; ветка выполняется только когда `cache.use` включён в конфиге класса (по умолчанию — выключен), либо когда её всё же вызывают.

На событии `call` во все методы, кроме `createAccount`, автоматически подмешивается `access_token` текущего аккаунта — передавать его вручную не нужно. На событии `prepare_response` (единственная точка разбора ответа во всём драйвере, полностью подменяющая стандартный JSON-decode `IntegrationDriver`): если `http_code` вне `200..299` — тело кладётся в `error`; иначе тело декодируется как JSON и проверяется поле `ok` — при `ok = false` или отсутствии `ok` в `error` кладётся текст ошибки Telegraph (или служебное сообщение), а при успехе в `response` остаётся **только** `result` (закодированный обратно в JSON), то есть обёртка `{ok, result}` снимается прозрачно для вызывающего кода.

## Зарегистрированные методы

- **`createAccount`** — создание аккаунта Telegraph (обычно вызывается автоматически конструктором, но доступен и напрямую).
  ```php
  $telegraph->call('createAccount', ['short_name' => 'MyBlog']);
  ```
  Параметры: `short_name` (обязателен), `author_name` (опционально), `author_url` (опционально, должен быть валидным URL).

- **`createPage`** (`POST`) — создание страницы.
  ```php
  $telegraph->call('createPage', [
      'title'   => 'Заголовок',
      'content' => '<p>Текст <b>статьи</b></p>', // HTML-строка, массив Node[] или \DOMDocument
  ]);
  ```
  Параметры: `title` (обязателен), `content` (обязателен — строка HTML, массив узлов или `\DOMDocument`), `author_name`/`author_url` (если не переданы — подставляются из значений, использованных при создании аккаунта), `return_content` (`nullable|bool` — вернуть ли содержимое созданной страницы в ответе). `content` перед отправкой всегда нормализуется в JSON-массив узлов Telegraph (см. ниже).

- **`editPage/{path}`** (`POST`) — редактирование существующей страницы.
  ```php
  $telegraph->call('editPage/{path}', ['path' => 'MyBlog-07-14', 'title' => 'Новый заголовок', 'content' => '<p>...</p>']);
  ```
  `path` — обязателен (непустая строка, автоматически обрезается от `/` по краям), `title`/`content`/`author_name`/`author_url`/`return_content` — как в `createPage`, но все опциональны.

- **`getPage/{path}`** — получение страницы по пути.
  ```php
  $telegraph->call('getPage/{path}', ['path' => 'MyBlog-07-14', 'return_content' => true]);
  ```
  `path` — обязателен, `return_content` — `nullable|bool`.

## Конвертация содержимого: HTML/DOM → `Node[]`

Приватный `normalize_content($content): array` принимает уже готовый массив узлов (возвращается как есть), `\DOMDocument`, либо HTML-строку (парсится через `\DOMDocument::loadHTML()` с подавлением libxml-ошибок). Дальше `parse_nodes_recursive()` рекурсивно обходит DOM и строит массив узлов формата Telegraph (`['tag' => ..., 'children' => [...], 'attrs' => [...]]`), используя карту тегов `self::$nodes_map`:

- `h1`/`h2`/`h3` → `h3`, `h4`/`h5`/`h6` → `h4` (Telegraph поддерживает только `h3`/`h4`);
- `p` → `p`, `li` → `p`;
- `div`/`section`/`article`/`ul`/`ol` → `null` (сам тег отбрасывается, но его дочерние узлы разбираются и поднимаются на уровень выше, "разворачивая" обёртку);
- `a`, `img`, `strong`/`b` → `strong`, `em`/`i` → `em`, `u`, `blockquote` — сохраняются как есть;
- любой тег, не упомянутый в карте — тоже "разворачивается" (сам отбрасывается, дети поднимаются наверх), как и явные `null`-записи в карте.

Текстовые узлы схлопывают повторяющиеся пробелы (`preg_replace('/\s+/u', ' ', ...)`) и отбрасываются, если после `trim()` пусты. Атрибуты `href`/`src`/`srcset` относительных ссылок нормализуются в абсолютные через `normalize_url()` (на основе `base_url` инстанса, определённого при создании).
