# Response.php

---

## 1. Концепция

`Response` — **флюентный строитель HTTP-ответа**. Финальный класс.

Методы вызываются как **статически** через `__callStatic`, который создаёт новый экземпляр. `send()` — единственный по-настоящему публичный метод.

Цепочка строителя: `Response::json($data)->status(200)->header('X-Custom', 'val')->send()`.

После `send()` отправляет заголовки, тело, вызывает `fastcgi_finish_request()` если доступно, затем `exit`.

---

## 2. Публичные методы-строители (static)

Все методы ниже вызываются статически (создают новый экземпляр) или на экземпляре в цепочке.

### `json(mixed $data, int $status = 200, int $json_options = ...): self`

Ответ с JSON-телом.

| Параметр | Энциклопедия | Описание |
|----------|---------|----------|
| `$data` | `mixed` | Данные для сериализации |
| `$status` | `int` | HTTP-код. Умолч: 200 |
| `$json_options` | `int` | Флаги `json_encode`. Умолч: `JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES` |

**Бросает:** `RuntimeException` если JSON-сериализация не удалась.

```php
Response::json(['ok' => true, 'data' => $items])->send();
Response::json(['error' => 'Not found'], 404)->send();
```

---

### `html(string $html, int $status = 200): self`

Ответ с HTML-телом (`Content-Type: text/html; charset=UTF-8`).

```php
Response::html('<h1>Hello</h1>')->send();
```

---

### `text(string $text, int $status = 200): self`

Ответ с плаинтекстом (`Content-Type: text/plain; charset=UTF-8`).

---

### `raw(mixed $data, int $status = 200): self`

Ответ без авто заголовка `Content-Type`. Полезен когда `Content-Type` задаётся вручную.

---

### `redirect(string $url, int $status = 302): self`

Перенаправление.

```php
Response::redirect('/login')->send();
Response::redirect('/new-url', 301)->send(); // постоянное
```

---

### `file(string $full_path, string $file_name = '', bool $download = false): self`

Ответ с файлом. Автоопределяет MIME (через `finfo` или `mime_content_type`), добавляет `ETag` и `Last-Modified`.

| Параметр | Описание |
|----------|----------|
| `$full_path` | Абсолютный путь к файлу |
| `$file_name` | Имя для `Content-Disposition`. Пусто  — используется `basename($full_path)` |
| `$download` | `true` — `attachment`, `false` — `inline` |

**Бросает:** `InvalidArgumentException` если файл не найден или недоступен.

```php
Response::file('/var/files/report.pdf')->send(); // inline
```

---

### `download(string $full_path, string $file_name = ''): self`

Удобный алиас для `file(..., download: true)`.

```php
Response::download('/var/files/report.pdf', 'Otchet.pdf')->send();
```

---

### `stream(callable $callback, int $status = 200): self`

Ответ с потоковым телом. `$callback` вызывается внутри `send()` и должен сам осуществлять `echo`.

```php
Response::stream(function() {
    echo 'chunk 1';
    flush();
    echo 'chunk 2';
})->send();
```

---

### `stream_download(callable $callback, string $file_name, int $status = 200): self`

Потоковая выгрузка: `Content-Type: application/octet-stream` + `Content-Disposition: attachment`.

---

### `cookie(string $name, string $value = '', array $options = []): self`

Устанавливает cookie через `setcookie($name, $value, $options)`. возвращает `self` для цепочки.

---

### `header(string $key, string $value): self`

Добавляет HTTP-заголовок. Имя автоматически нормализуется в Title-Case.

```php
Response::json($data)->header('X-Custom-Header', 'value')->send();
```

---

### `headers(array $headers): self`

Добавляет несколько заголовков сразу.

---

### `status(int $code): self`

Устанавливает HTTP-статус. Умолч: 200.

---

### `send(): void`

**Действие:** очищает буфер вывода, отправляет `http_response_code`, заголовки, затем тело. Вызывает `fastcgi_finish_request()` если доступно. Завершает выполнение через `exit`.

Файлы отправляются через стриминг чанками (8192 байт).

```php
Response::json(['ok' => true])
    ->status(201)
    ->header('Location', '/api/resource/1')
    ->send();
// exit’нется автоматически
```
