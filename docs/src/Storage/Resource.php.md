# Resource.php

Базовый носитель контента: «именованный блоб + mime-сервис + резолвер», **не привязанный к
диску или сети**. Хранит тело в памяти (`$raw`) и `SplFileInfo`, построенный из «имени». Две
конкретизации: [`File`](File.php.md) (материализация на диск/HTTP) и ответы
[`WebClient`](../HTTP/WebClient.php.md) — поле `data` каждого результата это in-memory
`Resource` над телом ответа (ленивый декод по MIME, без диска/сети).

## Создание

```php
Resource::make([
    'body'     => $bytes,          // тело (-> $raw); null = «не материализован»
    'mime'     => 'application/json', // явный MIME (-> mime_override)
    'name'     => 'inline',        // имя для SplFileInfo (путь/URL у File)
    'id'       => 'https://…',     // идентификатор для производных кешей (extract)
    'original' => $parent,         // цепочка исходников (getOriginal)
    'options'  => [ /* атрибуты */ ],
]);
```

`File` конструируется своими фабриками (путь/URL) и переопределяет диск/сетевое поведение.

## MIME-резолвинг

MIME определяется `getMime()` (`mime_override` → карта расширений), затем
`resolveMimeService()` подбирает класс из `config('mimes.services')` **по вхождению подстроки**
Content-Type (`text/html` → `HtmlMime`, `application/json` → `JsonMime`, `application/xml`/
`text/xml` → `XmlMime`, префиксы `image/`, `font/`, …). Нет совпадения → анонимный `Mime`
(тождественные `get`/`set`, без разбора).

## Доступ к данным

- `get()` / `getContents()` — декод собственного тела: `mime()->get($raw)` (json → массив,
  html/xml → текст).
- `set($data, &$flags)` — кодирование через `mime()->set()`.
- `getRaw(bool $force = false)` — сырое тело (в базе бросает «not materialized», если `$raw` null;
  `File` переопределяет чтением диска/сети).
- `getId()` — идентификатор содержимого (pathname у `File`, ключ запроса/effective_url у
  `WebClient`); используется как ключ производных кешей (`Extractable::extract`).
- `setMime($mime)` / `getMime()` / `getServiceName()`.

## Проксирование методов (`__call`)

Неизвестный метод сначала пробуется на `SplFileInfo` (`getPathname`/`getExtension`/… — от них
зависит резолв MIME), затем на mime-сервис (`extract`, `getDom`, `toArray`, `toHTML`, …).
Результаты mime-методов мемоизируются в `$mime_data` по имени+хешу аргументов. Поэтому у ответа
`WebClient` можно звать `$result['data']->extract($schema)` / `->getDom()` / `->get()`.

## Прочее

- `getOriginal(bool $force = false)` — предыдущий/корневой источник цепочки.
- `getRelativePath($root = '')`, `purge(bool $storage = true)`.
- Конфиг `mimes.extensions` (расширение → MIME) и `mimes.services` (MIME → класс) —
  единая точка регистрации форматов; новый формат = новый `Mime`-класс в карте.
