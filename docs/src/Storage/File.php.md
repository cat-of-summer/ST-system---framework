# File.php

Материализованная на диск/сеть реализация [`Resource`](Resource.php.md): работает с локальным
путём **или** URL. Локальные операции — чтение/запись/поиск файлов; URL — скачивание в кеш-файл.
Скачивание и метаданные теперь идут через [`WebClient`](../HTTP/WebClient.php.md): транспорт
владеет HTTP-кешем/ревалидацией и повторами, а `File` лишь материализует тело в свой кеш-файл
(его путь и `mtime` — контракт для производных кешей).

## Конфигурация (`getDefaultConfig`)

```
cache   => [ 'dir' => '~/cache/', 'ttl' => 3600 ]
request => [ 'headers' => [], 'follow_redirects' => true, 'delay' => 0,
             'connect_timeout' => 10, 'timeout' => 300, 'max_attempts' => 15 ]
find    => [ 'max_files' => 50, 'sym_links' => false, 'recursive' => true, 'hidden_files' => false ]
```

`request.*` читаются per-instance с fallback на конфиг: `headers`, `follow_redirects`, `delay`
(троттлинг по хосту), `connect_timeout`, `timeout`, `max_attempts` (→ `requeue = max_attempts−1`).

## Фабрики

- `File::fetch($path, $force = false, $opts = [])` — `make(...)->fetch()`; возвращает `File` над
  локальным кеш-файлом.
- `File::make($path, $opts = [])` — экземпляр без скачивания.
- `File::find($input, $config = [])` — поиск локальных файлов (glob/regex/рекурсия) → `File[]`.
- `File::exists($path)` — существование локального файла (для URL всегда `false`).

## Скачивание URL

### `fetch(bool $force = false): self`

1. Строит `WebClient` с `cache.use = !$force`, каталогом/TTL из `File::config('cache.*')`,
   `requeue = max_attempts−1`, `verify` по схеме (`https` → проверяем, `http` → нет).
2. `WebClient` решает свежесть/ревалидацию (ETag/Last-Modified/max-age) — при свежем кеше сети нет.
3. Тело пишется в кеш-файл **только когда пришло из сети** (`cached = false`): свежий кеш-хит
   файл не трогает → `mtime` сохраняется (от него зависят производные кеши).
4. Метаданные (`http_code`, `effective_url`, `content-type`, `content_length`, `fetched_at`,
   `expires_in`, …) сохраняются в мету кеша `File`.
5. На сбое/`≥400` при наличии старого файла — отдаётся он (протухшее лучше падения); иначе
   исключение. Повторы транзиентных сбоев (`errno`/`≥500`) — через `requeue`.

### `getMeta(bool $force = false): array`

Для URL — `HEAD` через `WebClient` (кешируется), обновляет заголовки/`effective_url`/
`content-length`/`http_code` с гейтом `headers_cache_expires_in`. Для локального файла — мета кеша.

## Прочее

- `getRaw(bool $force = false)` — байты: локально `file_get_contents`, для URL — `fetch()` + чтение.
- `putContents($raw, $flags = 0)` — кодирует через mime-сервис и пишет.
- `getMime()` — `mime_override`/расширение → для URL `Content-Type` из `HEAD`-меты → наследование
  от исходного URL → `finfo`/`mime_content_type` для локального файла.
- `getSize`, `getDirectory`, `purge`, `purgeAll`, `diskFreeSpace`/`diskTotalSpace`, `mtime`.
- Виртуальные атрибуты (`attributeMap`): `real_path`, `directory`, `size`, `type`, `exists`,
  `ctime`, `atime`, `is_dir`, `is_file`, `is_readable`, `is_writable`, `is_link`.
