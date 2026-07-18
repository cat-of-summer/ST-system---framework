<!-- DOCGEN:START -->
# UploadedFile.php
<!-- DOCGEN:END -->

`namespace ST_system\HTTP`

## Назначение

`UploadedFile extends Storage\Resource implements \ArrayAccess` — объектная обёртка над
**одной** записью `$_FILES`. Инкапсулирует всю работу с загруженными файлами: проверку
подлинности загрузки (`is_uploaded_file`), чтение реального MIME (`finfo`), перенос во
«взрослое» хранилище (`move_uploaded_file`). Именно эти объекты возвращает `Request::files()`
(список на каждое поле формы), и с ними же работают правила валидации `files`, `mime`,
`extension`, `filesize` (см. [[Rule.php]]).

Живёт рядом с `Request` в `ST_system\HTTP` (это часть HTTP-слоя), но наследует хранилищную
базу [[Resource.php]]: доступ к атрибутам через `__get`, делегирование методов `SplFileInfo`
через `__call`. Поэтому `getFilename()`, `getExtension()`, `getBasename()` доступны из коробки
— считаются по **клиентскому имени** файла.

## Создание объекта: `fetch()` и `make()`

### `static fetch(?string $field = null): array`

Разбирает суперглобал `$_FILES` в объекты `UploadedFile` — основной способ получить загрузки:

```php
use ST_system\HTTP\UploadedFile;

$all   = UploadedFile::fetch();          // ['avatar' => UploadedFile[], 'docs' => UploadedFile[]]
$files = UploadedFile::fetch('avatar');  // UploadedFile[] только этого поля (или [])
```

- Нормализует «рваную» структуру `$_FILES`, в т.ч. multi-file `name="files[]"`, в плоский
  список **на каждое поле**.
- Пропускает пустые слоты (`error === UPLOAD_ERR_NO_FILE`); все остальные записи —
  **включая битые** (`UPLOAD_ERR_INI_SIZE`, `UPLOAD_ERR_PARTIAL` и т.п.) — оборачиваются в
  объекты, а отсеиваются уже правилами `files`/`extension`/`mime` на этапе валидации.
- Результат **кэшируется** в статической переменной: перебор `$_FILES` выполняется один раз
  за процесс, повторные вызовы (в т.ч. с разным `$field`) отдают уже разобранную карту.

Именно `fetch()` вызывает `Request::_files()` под капотом, поэтому `Request::files('audio')`
возвращает результат `UploadedFile::fetch('audio')`.

### `static make(array $entry): UploadedFile`

Создаёт объект из одной готовой записи (унаследована от `Resource`, конструктор `protected`):

```php
$file = UploadedFile::make([
    'name'     => 'holiday.MP4',    // клиентское имя (as-is)
    'tmp_name' => '/tmp/phpXXXXXX', // временный путь PHP
    'type'     => 'video/mp4',      // MIME, заявленный браузером (не доверенный)
    'size'     => 1048576,          // размер в байтах
    'error'    => UPLOAD_ERR_OK,    // код ошибки из $_FILES
]);
```

В обычном потоке приложения `make()` вручную не нужен — им пользуется `fetch()`.

## Валидность и метаданные

- **`isValid(): bool`** — нет ошибки загрузки И путь непуст И `is_uploaded_file()`.
  Единственный источник истины «файл действительно загружен».
- **`hasError(): int`** — код `UPLOAD_ERR_*`. Возвращает **саму ошибку**, но `UPLOAD_ERR_OK === 0`,
  поэтому метод работает и как предикат: `if ($file->hasError()) { ... }`.
- **`getFilename(): string`** — клиентское имя файла (из `SplFileInfo`, унаследовано от `Resource`).
- **`getExtension(): string`** — расширение из клиентского имени (регистр **как прислал
  клиент**; для нижнего регистра — `toArray()['extension']` или атрибут `extension`, см. ниже).
- **`getMime(): string`** — MIME по той же схеме, что и [[File.php]]`::getMime()`: сначала
  `Resource::getMime()` (явный `mime_override`, затем **таблица типов** `mimes.extensions`),
  если там пусто — определение по содержимому через общий с `File` трейт [[HasMime.php]]
  (`finfo`, фолбэк `mime_content_type`). Именно это значение проверяет правило `mime`.
- **`getClientType(): string`** — сырой тип, **заявленный клиентом** (`$_FILES['type']`).
  Не участвует в `getMime()` и не используется правилами — см. предупреждение ниже.
- **`getSize(string $unit = 'b')`** — размер. Сигнатура и поведение как у [[File.php]]`::getSize()`:
  по умолчанию (`'b'`) — число байт (`int`), с другим `$unit` — форматированное значение
  (`Main::formatBytes`). Правило `filesize` сравнивает `getSize('b')`.
- **`getPath(): string`** — путь к файлу на диске: временный до `save()`, целевой — после.
- **`getRaw(bool $force = false)`** — сырое содержимое файла (`file_get_contents`);
  бросает `\LogicException`, если файла нет.

> ⚠️ **Не путайте `getMime()` и `getClientType()`.** `$_FILES['type']` целиком контролируется
> отправителем и подделывается тривиально, поэтому в `getMime()` он не участвует вовсе.
> `getClientType()` держите для логов/метаданных, но не для решений о безопасности.
>
> При этом учтите: для расширений, перечисленных в `mimes.extensions` (по умолчанию это
> веб-ассеты — `txt`, `html`, `css`, `js`, `svg`, …), `getMime()` возвращает тип **по таблице**,
> не читая содержимое, а расширение берётся из клиентского имени. Так работает и `File`. Для
> таких расширений `mime:` фактически дублирует `extension:`; если нужен вывод строго по
> содержимому — проверяйте `finfo` отдельно.

### Доступ как к атрибутам

Атрибуты объявлены явно в `attributeMap()` поверх базовой карты [[Resource.php]] — тем же
способом, что и в [[File.php]]. Работает штатный механизм `Traits\HasAttributes`
(см. [[HasAttributes.php]]): `get{Studly}Attribute()` → `attributeMap()` → сырые `attributes`.

```php
$file->mime;         // getMime()
$file->size;         // getSize()  — байты
$file->error;        // hasError() — код UPLOAD_ERR_*
$file->path;         // getPath()  — путь к файлу на диске
$file->filename;     // из карты Resource — клиентское имя
$file->extension;    // из карты Resource (регистр клиента)
```

Кэширование отключено для всех — они зависят от состояния файла, а путь меняется после
`save()`. Атрибут `path` намеренно **перекрывает** кэшируемый вариант из [[Resource.php]].
Методы, не объявленные в карте (например `getRaw()`), атрибутами **не** становятся.

## `ArrayAccess` и `toArray()`

Класс реализует `ArrayAccess`, поэтому доступ по ключам записи `$_FILES` работает **прямо на
объекте**, без конвертации:

```php
$file['tmp_name'];   // временный путь
$file['name'];       // клиентское имя
$file['type'];       // клиентский MIME
$file['size'];       // байты
$file['error'];      // код ошибки
$file['extension'];  // расширение (нижний регистр)
```

`offsetSet`/`offsetUnset` бросают `\LogicException` — объект read-only. Метод `offsetGet`
помечен `#[\ReturnTypeWillChange]`: это подавляет deprecation PHP 8.1+ (интерфейсный
`offsetGet(): mixed`) при сохранении совместимости с PHP 7.4, где `mixed` как тип недоступен,
а строка `#[...]` трактуется как комментарий. Убирать не нужно.

`toArray(): array` возвращает ту же ассоциативную форму (`name`, `tmp_name`, `type`, `size`,
`error`, `extension`) — для контекстов, где нужен **настоящий** `array` (json_encode,
`array_*`, спред, аргумент с type-hint `array`). Правило `array` умеет коэрсить объект с
`toArray()` автоматически (`file|array`, см. [[Rule.php]]).

> **Про ключ `extension`.** В прежней реализации записи `$_FILES` ключ назывался `extenstion`
> (историческая опечатка). Она устранена — везде теперь `extension`.

## `save()` — материализация в `Storage\File`

```php
public function save(string $destination): File
```

Обёртка над `move_uploaded_file()`, превращающая загрузку в постоянный файл и возвращающая
[[File.php]]:

1. Если `!isValid()` — бросает `\RuntimeException` (сохранять битую/поддельную загрузку нельзя).
2. Резолвит путь назначения через `File::make($destination)` → `$target->pathname` (поддерживает
   `~/...` и абсолютные пути; резолвом занимается сам `File`/`Main::preparePath`).
3. Создаёт каталог назначения (`@mkdir(..., 0775, true)`) при необходимости.
4. Переносит временный файл через `move_uploaded_file()`. При неудаче — `\RuntimeException`.
5. Обновляет внутренний `tmp_name` на новый путь и возвращает `File`.

```php
$stored = $request->files('audio')[0]->save("~/public/uploads/{$id}.mp3");
$stored->getPathname();   // абсолютный путь сохранённого файла
```

## Валидация: предикаты и `rules()`

Вся доменная логика проверок загрузок живёт здесь, а не в [[Rule.php]]. `Rule` только
регистрирует правила и добавляет свою семантику (sentinel, required-scope).

### `static filter($value, callable $keep): array`

Нормализует значение (одиночный `UploadedFile` / список / что угодно другое) и отбирает
прошедшие `$keep`. Возвращает кортеж `[UploadedFile[] отобранные, bool была ли одиночка]` —
про сентинел и `Rule` не знает, поэтому пригоден и для собственной фильтрации:

```php
[$kept, $single] = UploadedFile::filter($request->files('docs'), fn($f) => $f->isValid());
```

### `static registerRules(): void` — правила создаёт сам `UploadedFile`

Объекты правил `files` / `mime`(`mimes`) / `extension` / `filesize` собираются **здесь**, а не
в [[Rule.php]]. `Rule::init()` лишь один раз дёргает `\ST_system\HTTP\UploadedFile::registerRules()`
(полностью квалифицированным вызовом — импорта `UploadedFile` в `Rule` нет).

Каждое правило пишется тем же плоским стилем, что и корневые правила `Rule`: фабрика
`Rule::filtered()` даёт семантику sentinel/required-scope, дальше — `order`, сообщение, алиасы.
Сама проверка живёт прямо в правиле:

```php
// extension:ext1,ext2 — по клиентскому имени, регистронезависимо.
Rule::filtered(fn($v, array $p) => static::filter($v, fn(self $f) =>
    $f->isValid() && in_array(strtolower($f->getExtension()), array_map('strtolower', $p), true)
))
->order(600)
->handleError(fn($v) => 'The file extension is not allowed')
->alias('extension');
```

Вызов `isValid()` в каждой проверке — это и есть «`files` подмешивается автоматически»
в `extension`/`mime`/`filesize`.

**Чтобы добавить новое правило загрузок** — допишите такой же блок в `registerRules()`.
Трогать `Rule` не нужно.

> Взаимная загрузка `Rule` ↔ `UploadedFile` безопасна: `registerRules()` вызывает
> `Rule::create()`, та зовёт `Rule::init()`, но `init()` выставляет свой флаг «уже выполнено»
> до регистраций, поэтому повторный вход завершается сразу.

## Соответствие методов `File` ↔ `UploadedFile`

Одинаковые имена — одинаковый смысл: `getMime()` (реальный content-MIME), `getSize($unit)`
(байты/формат), `getRaw()` (сырое содержимое), `getFilename()`/`getExtension()`/`getBasename()`
(из `SplFileInfo`), а также `getPath()`. Специфичны для загрузки: `isValid()`, `hasError()`,
`save()`, `fetch()`, `filter()`, `registerRules()`, `toArray()`.

## Типичный сценарий (контроллер)

```php
public function upload(UploadRequest $request): Response {
    // Валидация (required|files|count:1|extension|mime) уже прошла в UploadRequest::__schema()
    $file = $request->files('audio')[0];

    $stored = $file->save("~/public/uploads/{$id}.{$file->getExtension()}");

    return Response::json(['mime' => $file->getMime(), 'size' => $file->getSize('b')]);
}
```

## Связанные страницы

[[Resource.php]] · [[File.php]] · [[HasAttributes.php]] · [[Rule.php]] · [[Request.php]]
