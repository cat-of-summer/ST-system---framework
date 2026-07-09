# File


## 1. Концепция

Единый объект для работы с локальными файлами и удалёнными URL. Локальный путь `~` раскрывается через `Main::preparePath`. URL-ресурсы скачиваются, кэшируются и возвращаются как локальный `File`. MIME определяется автоматически по расширению или `finfo`; на основе MIME выбирается соответствующий сервис (`CssMime`, `JsMime`, `SvgMime`, `ImageMime`, `FontMime`…).

**Конструктор ленив.** Ни MIME-сервис, ни кэш не создаются при `File::make()` — только при первом реальном обращении. Это важно для `find()`, который может вернуть десятки объектов: без лени каждый файл без известного расширения (`.png`, `.jpg`) получал бы `finfo`-снифф содержимого просто для того, чтобы быть созданным.

```php
// Локальный файл
$file = File::make('~/assets/app.css');
echo $file->getMime();     // 'text/css'
echo $file->getSize('kb'); // размер в КБ

// Атрибуты — те же данные как свойства
echo $file->extension;     // 'css'
echo $file->relative_path; // '/assets/app.css'
echo $file->mtime;         // 1783589116

// Минификация (для CSS, JS)
$min = $file->minify();
echo $min->getPathname();  // ~/cache/<md5(путь к исходнику)>/app.min.css

// toHTML() — тег <link>/<script>
echo $file->toHTML(['media' => 'screen']);

// Загрузка удалённого ресурса с кэшированием
$remote = File::make('https://cdn.example.com/lib.js');
$local  = $remote->fetch();  // скачивает в ~/cache/

// Поиск файлов
$files = File::find('~/assets/.*\.js');                    // регулярное выражение
$files = File::find('~/assets/', ['extension' => 'css']);  // по расширению

// Очистка кэша
$file->purge(false);  // забыть закэшированное в памяти
$file->purge();       // забыть и удалить с диска
File::purgeAll();     // снести всю директорию кэша
```

> `find()` принимает **регулярное выражение**, а не glob. `'*.js'` будет разобрано как regex (где `*` — квантификатор), а не как маска. Для «всех js» пишите `'.*\.js'` или передайте директорию с `['extension' => 'js']`.

---

## 2. Атрибуты

`File` использует трейт `HasAttributes`. Атрибут — это **проекция метода на его аргументы по умолчанию**: `$file->relative_path` эквивалентно `$file->getRelativePath('')`, а параметризованная форма `$file->getRelativePath('/assets')` по-прежнему вызывается как метод.

| Атрибут | Метод | Кэшируется |
|---|---|---|
| `relative_path` | `getRelativePath()` | да |
| `pathname` | `getPathname()` | да |
| `filename` | `getFilename()` | да |
| `basename` | `getBasename()` — без расширения | да |
| `extension` | `getExtension()` | да |
| `path` | `getPath()` | да |
| `service_name` | `getServiceName()` | да |
| `original` | `getOriginal()` | да |
| `real_path` | `getRealPath()` | нет |
| `directory` | `getDirectory()` | нет |
| `size` | `getSize('b')` | нет |
| `type` | `getType()` — для URI `'uri'` | нет |
| `exists` | `exists()` | нет |
| `mtime`, `ctime`, `atime` | `filemtime()` и т.д. | нет |
| `is_dir`, `is_file`, `is_readable`, `is_writable`, `is_link` | `SplFileInfo` | нет |
| `is_uri` | URL ли это | — |
| `headers`, `delay`, `follow_redirects`, `connect_timeout`, `timeout`, `max_attempts`, `header_list` | параметры HTTP-запроса | — |

Кэшируются только значения, неизменные в течение жизни объекта. Всё, что читает файловую систему (`size`, `mtime`, `is_file`), пересчитывается при каждом обращении, чтобы долгоживущий процесс не залипал на первом снимке.

**Атрибутами намеренно не сделаны:** `mime` (столкнулся бы с приватным свойством `$mime`, где лежит объект сервиса, а не строка — используйте `getMime()`), `contents` и `raw` (чтение файла целиком и сетевой `fetch()` для URI), `meta` (для URI делает HEAD-запрос).

`isset($file->mtime)` и `$file->mtime ?? 0` работают как ожидается — трейт определяет `__isset`.

---

## 3. Публичные методы

### `static make(string $path, array $options = []): static`
Создаёт объект файла. `~` в начале — корень приложения. `$options` — переопределение атрибутов HTTP-запроса (`timeout`, `headers`, `delay`…).

### `static find(string|array $input, array $config = []): array`
Поиск файлов. `$input` — путь, директория или **регулярное выражение**. Конфиг: `extension`, `max_files` (50), `sym_links`, `recursive` (true), `hidden_files`, `fallback` (`'make'` / `'throw'`).

### `fetch(bool $force = false): static`
Для URI: скачивает файл в кэш (повторяет до `request.max_attempts` раз) и возвращает новый `File` над кэш-файлом. Для локального — возвращает `$this`. Первым делом вызывает `purge(false)`.

> `fetch()` — это «материализовать содержимое», а не «сбросить кэш». Для URI он делает HEAD-запрос и может скачать тело целиком. Чтобы просто забыть закэшированное, используйте `purge(false)`.

### `getMeta(bool $force = false): array`
Для URI: делает HEAD-запрос, кэширует заголовки. Возвращает массив с `http_code`, `content-type`, `content-length`, `expires_in` и т.д. Для локального файла — содержимое `.meta`-сайдкара.

> Для URI запись метаданных идёт с `append = false`: `fetch()` и обновление заголовков **перезаписывают документ целиком**. Не храните там ничего своего — заведите отдельный слот кэша.

### `setMeta(array $meta, bool $append = true): static`
Записывает метаданные файла.

### `getMime(): string`
MIME-тип. Сначала из таблицы расширений, потом из заголовков URI или `finfo`. Ресурс `finfo` открывается один раз на процесс.

### `getSize(string $unit = 'b'): int|float|string`
Размер. `$unit` передаётся в `Main::formatBytes()`, поэтому доступны все его режимы:

```php
$file->getSize();            // 3690987520 (int)
$file->getSize('mb');        // 3520.0 (float)
$file->getSize('GB MB KB');  // '3 GB 448 MB 0 KB'
```

### `static diskFreeSpace(string $path = '~', string $format = 'b'): int|float|string`
### `static diskTotalSpace(string $path = '~', string $format = 'b'): int|float|string`
Обёртки над `disk_free_space()` / `disk_total_space()`. `$format` — как у `getSize()`. Если передан путь к файлу, берётся его директория. При ошибке бросают `RuntimeException` вместо warning + `false`.

```php
File::diskFreeSpace();               // 967024889856
File::diskFreeSpace('~', 'GB MB');   // '900 GB 626 MB'
File::diskTotalSpace('~', 'gb');     // 1006.85…
```

Занятое место — это `diskTotalSpace() - diskFreeSpace()`.

### `getOriginal(bool $force = false): static|null`
Исходный объект (`$force = true` — корень цепочки fetch).

### `purge(bool $storage = true): static`
`purge(false)` — «забыть»: очищает мемоизацию `$info_data` / `$mime_data`, кэш атрибутов, stat-кэш PHP (`clearstatcache`) и кэши в памяти у своего `Cache` и MIME-сервиса. Данные на диске не трогает.

`purge(true)` (по умолчанию) — то же плюс удаление кэш-директории файла. Рекурсивно проходит по цепочке `original`. Возвращает непосредственный оригинал либо `$this`.

```php
// В демоне, после сигнала о том, что файл изменился на диске
$file->purge(false);
echo $file->mtime; // свежее значение
```

> **Зачем `clearstatcache()`.** `is_file()`, `filemtime()`, `filesize()` и все методы `SplFileInfo` читаются из stat-кэша PHP. Если файл изменил или удалил **другой** процесс, они продолжают отдавать старое: `is_file()` вернёт `true` для удалённого файла, а `mtime` не сдвинется. Последнее особенно опасно — на `mtime` держится инвалидация `minify()` / `convert()` / `extract()`. (`file_exists()` — исключение, он не кэшируется.)

### `static purgeAll(): void`
Удаляет всю директорию кэша.

### `getServiceName(): string`
Имя класса MIME-сервиса (`CssMime`, `SvgMime`, `Default` и т.п.). Резолвит сервис, если тот ещё не создан.

### `setMime(string $mime): static`
Принудительно задаёт MIME и пересоздаёт сервис. Игнорируется, если файл локальный, существует и его сервис уже определён.

### `getRaw(): string` / `getContents(): mixed` / `putContents($raw, int $flags = 0)`
Сырое содержимое; содержимое, пропущенное через `Mime::get()`; запись через `Mime::set()`.

### Делегированные методы (через `__call`)
Все методы `SplFileInfo`: `getPathname()`, `getFilename()`, `getExtension()`, `getBasename()`, `getRealPath()`, `isDir()` и т.д. А также методы MIME-сервиса: `toHTML()`, `minify()`, `bySprite()`, `convert()`, `extract()` и т.п.

Мемоизируются только **чистые** методы `SplFileInfo` — те, что зависят лишь от строки пути: `getPathname`, `getFilename`, `getExtension`, `getBasename`, `getPath`. Всё, что делает `stat()` (`getSize`, `getMTime`, `isFile`, `getRealPath`…), проходит насквозь: у PHP для них уже есть собственный кэш, который чистится через `purge(false)`.

Результаты MIME-сервиса мемоизируются по имени метода и аргументам. В долгоживущем процессе этот кэш растёт — сбрасывайте `purge(false)`.
