<!-- DOCGEN:START -->
# Response.php
<!-- DOCGEN:END -->

`namespace ST_system\HTTP`

## Назначение

`Response` — построитель исходящего HTTP-ответа: статус-код, заголовки, тело (строка/JSON/HTML/файл/поток) и финальная отправка клиенту (`send()`). Класс `final`, инстанс всегда создаётся неявно через магический статический вызов.

Почти все "строительные" методы (`status`, `header`, `headers`, `redirect`, `raw`, `text`, `html`, `json`, `file`, `download`, `stream`, `stream_download`, `cookie`) объявлены **`private`**, но остаются доступны снаружи класса благодаря паре магических методов:

- `public static function __callStatic(string $name, array $arguments)` — создаёт новый экземпляр (`new static`) и вызывает на нём метод `$name` с переданными аргументами. Это точка входа для стиля `Response::json([...])`, `Response::status(401)`.
- `public function __call(string $name, array $arguments)` — то же самое, но на уже существующем экземпляре (`$this->{$name}(...)`). Именно он обслуживает цепочки вызовов после первого статического: `Response::status(401)->header(...)->cookie(...)`.

Каждый строительный метод возвращает `$this` (кроме случаев, описанных ниже), поэтому вызовы образуют fluent-цепочку, которая обязательно завершается вызовом публичного `send()`.

`Access.php` использует именно этот паттерн для формирования ответов при отказе в доступе, например:

```php
Response::status(401)->header('WWW-Authenticate', 'Basic realm="Restricted Area"')->send();
Response::status($code)->header('X-Content-Type-Options', 'nosniff')->send();
```

`HTTP\Route::handleRequest()` использует `Response::json(...)` для оборачивания ошибок и результатов контроллеров, не являющихся объектом `Response`.

## Строительные методы (доступны через магию как `Response::method(...)` или `$response->method(...)`)

- **`status(int $code): self`** — устанавливает код ответа (по умолчанию `200`).
- **`header(string $key, string $value): self`** — добавляет/переопределяет один заголовок. Имя заголовка нормализуется: разбивается по `-`, `_` и пробелам, каждая часть приводится к виду `Ucfirst`, части склеиваются через `-` (например, `content-type` и `content_type` дают `Content-Type`).
- **`headers(array $headers): self`** — то же самое пакетно, для ассоциативного массива `['Имя' => 'значение', ...]`.
- **`redirect(string $url, int $status = 302): self`** — устанавливает статус (по умолчанию `302`) и заголовок `Location`.
- **`raw($data, ?int $status = null): self`** — кладёт `$data` в тело ответа как есть (без изменения `Content-Type`). Статус устанавливается **только если передан явно** (`$status !== null`); при `null` текущий статус сохраняется.
- **`text(string $text, ?int $status = null): self`** — `Content-Type: text/plain; charset=UTF-8` + `raw()`.
- **`html(string $html, ?int $status = null): self`** — `Content-Type: text/html; charset=UTF-8` + `raw()`.
- **`json($data, ?int $status = null, int $json_options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): self`** — кодирует `$data` через `json_encode()`. При ошибке кодирования бросает `\RuntimeException('JSON encoding error: ' . json_last_error_msg())`. При успехе ставит `Content-Type: application/json; charset=UTF-8` и вызывает `raw()`.
- **`file(string $full_path, string $file_name = '', bool $download = false): self`** — отдаёт содержимое файла с диска потоково при `send()`. Бросает `\InvalidArgumentException`, если файл не найден или не читаем. Определяет MIME-тип через `finfo` (приоритетно) или `mime_content_type()`, иначе — `application/octet-stream`. Устанавливает заголовки `Content-Type`, `Content-Disposition` (`inline` или `attachment` в зависимости от `$download`; кавычки в имени файла заменяются на одинарные), `Content-Length`, `ETag` (`md5(путь|mtime|размер)`) и `Last-Modified` (GMT, из `filemtime()`).
- **`download(string $full_path, string $file_name = ''): self`** — то же, что `file($full_path, $file_name, true)` (принудительно `Content-Disposition: attachment`).
- **`stream(callable $callback, int $status = 200): self`** — сохраняет `$callback` для отложенного вызова внутри `send()`; сам колбэк отвечает за постепенный вывод данных (`echo`/`flush()` и т.п.).
- **`stream_download(callable $callback, string $file_name, int $status = 200): self`** — ставит заголовки `Content-Type: application/octet-stream` и `Content-Disposition: attachment; filename="..."`, затем делегирует в `stream()`.
- **`cookie(string $name, string $value = '', array $options = []): self`** — вызывает нативный `setcookie($name, $value, $options)` (формат опций PHP ≥ 7.3: `expires`, `path`, `domain`, `secure`, `httponly`, `samesite`).

## Установка кода ответа

Статус по умолчанию — `200` (значение свойства `$status`). Задать другой код можно двумя равнозначными способами:

```php
// 1) явным вызовом status() в начале цепочки
Response::status(404)->html(View::page('404'))->send();

// 2) вторым аргументом метода контента
Response::html(View::page('404'), 404)->send();
```

Ключевой момент: методы контента (`raw`, `text`, `html`, `json`) **не перезаписывают** уже установленный статус, если код им не передан явно. Параметр `$status` у них nullable (`?int $status = null`), и `raw()` вызывает `status()` только при `$status !== null`. Поэтому `status(404)->html(...)` корректно отдаёт `404`, а не сбрасывается обратно в `200`.

> ⚠️ Исключения — `redirect()` (всегда ставит статус, по умолчанию `302`), а также `stream()` / `stream_download()` (параметр `int $status = 200` не nullable и всегда применяется). Для потоков с нестандартным кодом передавайте его аргументом `stream()`, а не полагайтесь на предшествующий `status(...)`.

## `send(): void` — отправка ответа

Публичный, финальный метод цепочки. Порядок действий:

1. Сбрасывает все уровни буферизации вывода (`ob_end_clean()` в цикле).
2. Устанавливает HTTP-код (`http_response_code()`) и заголовок `Status: <код>`.
3. Отправляет все накопленные заголовки (`header($k.': '.$v, true)`).
4. Выводит тело — в порядке приоритета:
   - если задан `stream_callback` — вызывает его (`call_user_func`);
   - иначе если задан `file_path` (после `file()`/`download()`) — открывает файл (`fopen('rb')`), снимает лимит времени выполнения (`set_time_limit(0)`) и читает/выводит его блоками по 8192 байта с `flush()` после каждого блока; бросает `\RuntimeException`, если файл не открылся;
   - иначе выводит `$content` как есть (`echo`).
5. Если доступна `fastcgi_finish_request()` — вызывает её, чтобы отдать ответ клиенту и продолжить/завершить выполнение на сервере.
6. Завершает выполнение скрипта (`exit`).

Из-за `exit` в конце `send()` любой код после вызова цепочки `Response::...->send()` не выполнится.

## Примеры

```php
// Простой текстовый/JSON ответ
Response::text('OK')->send();
Response::json(['ok' => true])->send();

// Ответ с произвольным статусом и заголовком (как в Access.php)
Response::status(401)
    ->header('WWW-Authenticate', 'Basic realm="Restricted Area"')
    ->send();

// Редирект
Response::redirect('/login', 302)->send();

// Отдача файла на скачивание
Response::download('/var/data/report.pdf', 'Отчёт.pdf')->send();

// Потоковая отдача (например, генерация CSV на лету)
Response::stream(function () {
    echo "id,name\n";
    for ($i = 1; $i <= 3; $i++) {
        echo "{$i},item{$i}\n";
        flush();
    }
})->send();

// Кука + JSON
Response::json(['status' => 'ok'])
    ->cookie('session', 'abc123', ['httponly' => true, 'path' => '/'])
    ->send();
```
