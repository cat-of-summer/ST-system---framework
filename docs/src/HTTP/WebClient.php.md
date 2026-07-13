# WebClient

`ST_system\HTTP\WebClient` — единый HTTP-клиент поверх `curl_multi`. Один объект описывает **один URL** (простой или шаблонизированный) и разворачивается в пачку из 1..N параллельных запросов. Клиент умеет:

- параллельно слать запросы окнами (`batch`) с паузой (`delay`);
- размножать запросы декартовым произведением параметров — как в URL (`{param}`), так и в теле (маркеры `WebClient::param()`);
- валидировать параметры и тело через `Rule`;
- лениво декодировать ответ (`data` → `Storage\Resource`);
- кешировать GET/HEAD с ревалидацией по ETag/Last-Modified;
- отдавать события жизненного цикла запроса (`prepare`, `error`, `response`, …);
- возвращать упавшие запросы в очередь повторов (`requeue`);
- объединять несколько клиентов в общий конвейер (`group()`).

Очередь работает **в одну сторону**: выданный результат помечается обработанным и повторно не отправляется. Повторный `send()` после полного прохода вернёт `[]`; полный перезапуск — `reset()`.

---

## Содержание

- [Быстрый старт](#быстрый-старт)
- [Создание клиента и конфигурация](#создание-клиента-и-конфигурация)
- [Простые запросы](#простые-запросы)
- [Шаблонизация URL](#шаблонизация-url)
- [Тело запроса: schema() и fill()](#тело-запроса-schema-и-fill)
- [Параметризация тела маркерами param()](#параметризация-тела-маркерами-param)
- [Отправка: next(), send(), reset()](#отправка-next-send-reset)
- [Структура результата](#структура-результата)
- [Работа с data (Storage\Resource)](#работа-с-data-storageresource)
- [События](#события)
- [Повторы запросов (requeue)](#повторы-запросов-requeue)
- [Группы](#группы)
- [Кеширование](#кеширование)
- [Схема URL и verify](#схема-url-и-verify)
- [Обработка ошибок](#обработка-ошибок)
- [Справочник публичного API](#справочник-публичного-api)

---

## Быстрый старт

```php
use ST_system\HTTP\WebClient;

// один GET
$results = WebClient::create('https://api.example.com/status')->send();
echo $results[0]['status'];              // 200
$data = $results[0]['data']->get();       // декодированное тело (json -> массив)

// пачка параллельных GET по шаблону URL
$client = WebClient::create('https://api.example.com/{path}', ['batch' => 5])
    ->query(['path' => ['users', 'posts', 'comments']]);

while ($r = $client->next()) {
    echo "{$r['url']} -> {$r['status']}\n";
}
```

---

## Создание клиента и конфигурация

```php
WebClient::create(string $url, array $config = []): self
// или эквивалентно:
new WebClient(string $url, array $config = []);
```

`$url` — простой (`https://host/path`) или шаблонизированный (`https://host/{p}/get`). Плейсхолдеры извлекаются регуляркой `\{(\w+)\}`.

### Опции конфигурации

| Ключ | Тип | По умолчанию | Назначение |
|------|-----|--------------|------------|
| `timeout` | float | `30.0` | Общий таймаут запроса, сек (0 — без лимита). |
| `connect_timeout` | float | `10.0` | Таймаут соединения, сек. |
| `follow_redirects` | bool | `true` | Следовать ли за `3xx`. |
| `max_redirects` | int | `10` | Максимум редиректов. |
| `verify` | bool | `false` | SSL-проверки + схема URL по умолчанию (см. [verify](#схема-url-и-verify)). |
| `headers` | array | `[]` | Заголовки запроса (assoc `Key => Value` или список `"Key: Value"`). |
| `response_type` | string | `''` | Явный MIME для разбора тела; `''` — автоопределение по `Content-Type`. |
| `batch` | int | `10` | Размер окна параллельных запросов. |
| `delay` | int | `0` | Пауза между окнами, мс. |
| `method` | string | `'get'` | HTTP-метод: `get\|post\|put\|patch\|delete\|head\|options`. |
| `exception` | bool | `true` | Бросать ли исключение на необработанную ошибку. |
| `requeue` | int | `0` | Лимит повторов из события `error`: `0` — запрещены, `<0` — без лимита, `>0` — макс. на запрос. |
| `cache.use` | bool | `false` | Включить кеш (только GET/HEAD). |
| `cache.ttl` | int | `3600` | TTL кеша, сек. |
| `cache.dir` | string | `''` | Каталог кеша (для файлового драйвера). |
| `cache.driver` | string | `'filesystem'` | Драйвер кеша (`Cache\Manager`). |

```php
$client = WebClient::create('https://api.example.com/data', [
    'method'  => 'post',
    'timeout' => 15.0,
    'headers' => ['Authorization' => 'Bearer TOKEN', 'Accept' => 'application/json'],
    'batch'   => 20,
    'delay'   => 100,
]);
```

Глобальные значения по умолчанию можно переопределить через `HasConfig`:

```php
WebClient::setConfig(['timeout' => 60.0, 'verify' => true]); // для всех новых клиентов
$default = WebClient::config('timeout');                     // прочитать дефолт
```

---

## Простые запросы

```php
// GET
$r = WebClient::create('https://httpbin.org/get')->send();

// POST с телом
$r = WebClient::create('https://httpbin.org/post', ['method' => 'post'])
    ->fill(['name' => 'Иван', 'age' => 30])
    ->send();

// PUT / PATCH / DELETE — через 'method'
WebClient::create('https://api/user/1', ['method' => 'delete'])->send();

// JSON-тело: задайте Content-Type
WebClient::create('https://api/data', [
    'method'  => 'post',
    'headers' => ['Content-Type' => 'application/json'],
])->fill(['a' => 1, 'b' => [2, 3]])->send();
```

По умолчанию тело кодируется как `application/x-www-form-urlencoded`. Если среди заголовков есть `Content-Type: application/json`, тело сериализуется в JSON. Значения-файлы включают `multipart/form-data`.

---

## Шаблонизация URL

Плейсхолдеры `{name}` в URL заполняются через `query()`. Массивы значений перемножаются декартово.

```php
// 1 параметр, 3 значения -> 3 запроса
WebClient::create('https://api/{path}')
    ->query(['path' => ['a', 'b', 'c']])
    ->send();

// 2 параметра -> 2 x 2 = 4 запроса
WebClient::create('https://api/{ver}/{res}')
    ->query(['ver' => ['v1', 'v2'], 'res' => ['users', 'posts']])
    ->send();
// -> /v1/users, /v1/posts, /v2/users, /v2/posts

// query string тоже можно шаблонизировать
WebClient::create('https://api/search?q={q}&page={page}')
    ->query(['q' => ['php', 'curl'], 'page' => ['1', '2']])
    ->send();
```

`query()` можно вызывать повторно — значения по каждому ключу перезаписываются (не добавляются):

```php
$client = WebClient::create('https://api/{id}');
$client->query(['id' => ['1', '2']]);   // 2 запроса
$client->query(['id' => ['3']]);        // теперь 1 запрос: id=3
```

Валидация: каждое значение обязано быть непустой строкой или массивом непустых строк. Неизвестный параметр (не плейсхолдер и не маркер тела) — `InvalidArgumentException`. `query()` на нешаблонизированном URL без маркеров тела — `LogicException`.

---

## Тело запроса: schema() и fill()

`schema()` задаёт правила валидации тела (`Rule::object`, throwable). `fill()` заполняет тело — **одно тело на все запросы пачки**.

```php
$client = WebClient::create('https://api/register', ['method' => 'post'])
    ->schema([
        'email'    => 'required|email',
        'name'     => 'required|string|max:100',
        'age'      => 'sometimes|int|min:18',
    ])
    ->fill(['email' => 'a@b.ru', 'name' => 'Иван', 'age' => 25]);

$client->send();
```

- `schema()` использует синтаксис правил `Rule` (см. `docs/src/Rule.php.md`): pipe-строки (`'required|email'`), dot-ключи (`'address.city' => 'required'`), inline-`Rule`.
- `fill()` валидирует переданный массив против схемы; при нарушении бросает исключение (schema throwable).
- Смена `schema()` **обнуляет** ранее заполненное тело.
- `fill()` без предварительного `schema()` тоже работает — просто без валидации.

### Файлы и multipart

Значение-`\CURLFile` или строка вида `'@/путь/к/файлу'` (файл должен существовать) переключает отправку на `multipart/form-data`:

```php
WebClient::create('https://api/upload', ['method' => 'post'])
    ->fill([
        'title' => 'Отчёт',
        'file'  => '@/tmp/report.pdf',           // строка '@путь'
        'photo' => new \CURLFile('/tmp/img.jpg'), // или явный CURLFile
    ])
    ->send();
```

Отправка файлов методами `GET`/`HEAD` невозможна — `LogicException` при запуске.

---

## Параметризация тела маркерами param()

Маркер `WebClient::param('имя')` делает поле схемы участником декартова произведения — значения подаются через `query()` и подставляются в тело **отдельно для каждого запроса**. Это позволяет массово параметризовать POST-запросы.

```php
WebClient::param(string $name): object   // маркер поля тела
```

Маркер ставится **вместо** спеки поля (тогда без доп. правил) или **в массиве** вместе с правилами-модификаторами:

```php
$client = WebClient::create('https://api/{path}/', ['method' => 'post'])
    ->schema([
        'test'  => 'string',                              // обычное статическое поле
        'param' => [WebClient::param('p2'), 'string|email'], // параметризуемое + валидация
        'token' => WebClient::param('tok'),                // параметризуемое без правил
    ])
    ->fill(['test' => 'static'])                          // статические поля
    ->query([
        'path' => ['1', '2'],                             // плейсхолдер URL
        'p2'   => ['a@test.ru', 'b@test.ru'],             // маркер тела
        'tok'  => ['X'],
    ]);

$client->send(); // 2 (path) x 2 (p2) x 1 (tok) = 4 запроса
// каждый POST: body = ['test' => 'static', 'param' => <p2>, 'token' => 'X']
```

**Ключевые правила:**

- `schema()` обязан быть вызван **до** `query()` с маркерными именами — иначе имя маркера будет «неизвестным параметром» (`InvalidArgumentException` с подсказкой).
- Значения маркеров валидируются правилами-модификаторами **один раз** при `query()`, не для каждой комбинации. Тип не навязывается — определяется модификаторами (можно передавать не только строки).
- Маркерные поля в `fill()` необязательны (`sometimes`); подставленное значение всё равно перезапишет заполненное.
- Имя маркера может совпадать с плейсхолдером URL — тогда один query-параметр питает и URL, и тело (правило плейсхолдера приоритетно).
- Dot-ключи в схеме поддерживаются: `'user.email' => [WebClient::param('e'), 'email']` подставит значение в `$body['user']['email']`.
- Ошибки конфигурации маркеров (`InvalidArgumentException`): имя не `\w+`; один параметр привязан к нескольким полям; в одном поле более одного маркера.

```php
// пример: рассылка разных тел по одному эндпоинту
WebClient::create('https://api/notify', ['method' => 'post'])
    ->schema([
        'channel' => 'required|string',
        'user_id' => [WebClient::param('uid'), 'int'],
        'text'    => [WebClient::param('msg'), 'string'],
    ])
    ->fill(['channel' => 'sms'])
    ->query([
        'uid' => [1, 2, 3],
        'msg' => ['Привет'],
    ])
    ->send(); // 3 запроса с разными user_id
```

---

## Отправка: next(), send(), reset()

```php
public function next(): ?array   // один результат или null после исчерпания
public function send(): array    // все результаты пачки массивом
public function reset(): self    // полный перезапуск (сброс обработанных)
```

### Очередь «в одну сторону»

Выданный результат помечается обработанным и повторно не отправляется. Поэтому:

```php
$client = WebClient::create('https://api/{id}')->query(['id' => ['1', '2', '3']]);

$first  = $client->send();   // 3 результата
$second = $client->send();   // [] — всё уже обработано
$third  = $client->next();   // null

$client->reset();            // сброс: обработанные забыты
$again  = $client->send();   // снова 3 результата
```

`next()` и `send()` делят один конвейер — их можно чередовать:

```php
$r1 = $client->next();       // первый результат
$rest = $client->send();     // остальные
```

### Возобновление после исключения

Если посреди пачки бросается исключение (необработанная ошибка при `exception => true`), уже выданные результаты остаются помеченными, а недоставленные — нет. Повторный `send()`/`next()` дошлёт **только недоставленные** комбинации:

```php
$client = WebClient::create('https://api/{id}', ['batch' => 1])
    ->query(['id' => ['ok', 'bad', 'ok2']]);

$done = [];
try {
    while ($r = $client->next()) $done[] = $r;  // упадёт на 'bad'
} catch (\RuntimeException $e) {
    // $done содержит результат 'ok'
}

// после устранения причины — дошлём 'bad' и 'ok2', 'ok' не повторится
$rest = $client->send();
```

### Что сбрасывает обработанное

Полный сброс делают: `reset()`, а также `query()`, `schema()`, `fill()` (любое изменение запроса).

---

## Структура результата

Каждый результат (`next()` элемент / элемент массива `send()`) — ассоциативный массив:

| Ключ | Тип | Описание |
|------|-----|----------|
| `status` | int | HTTP-код ответа. |
| `headers` | array | Заголовки ответа, ключи в lowercase. |
| `body` | ?string | Сырое тело (`null` для HEAD/оборванного). |
| `data` | ?Resource | Ленивый декодер тела (`Storage\Resource`) или `null` при пустом теле. |
| `type` | string | MIME для разбора (`response_type` или `Content-Type`). |
| `url` | string | Чистый URL запроса (без query string). |
| `effective_url` | string | Итоговый URL после редиректов. |
| `errno` | int | Код ошибки curl (`0` — нет). |
| `error` | string | Сообщение ошибки curl. |
| `aborted` | bool | Тело оборвано (напр. на 304 при ревалидации). |
| `cached` | bool | Ответ отдан из кеша. |
| `config` | array | Действующий конфиг клиента-владельца. |
| `info` | array | Полный `curl_getinfo()`. |

```php
foreach (WebClient::create('https://api/{p}')->query(['p' => ['a', 'b']])->send() as $r) {
    printf("%s: %d (%s)\n", $r['url'], $r['status'], $r['cached'] ? 'cache' : 'net');
    if ($r['errno']) echo "  ошибка: {$r['error']}\n";
}
```

Ключ `config` особенно полезен в общем обработчике [группы](#группы) — по нему в `switch` различают, какому клиенту принадлежит результат.

---

## Работа с data (Storage\Resource)

`data` — `Storage\Resource` над телом с ленивым декодом по MIME (`type`):

```php
$r = WebClient::create('https://api/users.json')->send()[0];

$arr = $r['data']->get();          // json -> массив, html/xml -> текст/структура по mime
```

Для HTML/XML доступны извлечение по схеме и DOM:

```php
$html = WebClient::create('https://example.com/page.html')->send()[0];

$dom  = $html['data']->getDom();                    // \DOMDocument
$data = $html['data']->extract([                    // извлечение по схеме
    'title' => 'h1',
    'links' => 'a@href',
]);
```

Пустое тело (HEAD, оборванный ответ) → `data === null`. Явный формат разбора задаётся через `response_type`:

```php
WebClient::create('https://api/data', ['response_type' => 'application/json'])->send();
```

---

## События

Клиент использует `HasEvents`. Слушатели регистрируются через `on()`. Зарезервированные события жизненного цикла (нельзя вызвать через `trigger()`):

| Событие | Сигнатура | Когда |
|---------|-----------|-------|
| `prepare` | `fn(&$spec)` | До вычисления ключа кеша и настройки хендла. Мутации `$spec` влияют на запрос. |
| `prepare_response` | `fn($spec, &$result)` | После получения ответа, до декода. |
| `decode_response` | `fn($spec, &$result)` | Кастомный декод. Если нет слушателей или `data` не заполнена — `data` соберётся автоматически. |
| `error` | `fn($spec, &$result)` | При `errno != 0` или `status >= 400`. |
| `response` | `fn($spec, &$result)` | Всегда последним; единственное событие для кеш-хитов. |

Все события **per-request** (на каждый запрос пачки), к окнам не привязаны.

```php
$client = WebClient::create('https://api/{id}')->query(['id' => ['1', '2']]);

// добавить заголовок каждому запросу
$client->on('prepare', function (&$spec) {
    $spec['headers']['X-Request-Time'] = (string)time();
});

// логировать ответы
$client->on('response', function ($spec, $result) {
    echo "{$result['url']} -> {$result['status']}\n";
});

// перехватить ошибки (иначе — исключение при exception=true)
$client->on('error', function ($spec, &$result) {
    error_log("Ошибка {$result['url']}: {$result['status']}");
});

$client->send();
```

Наличие хотя бы одного слушателя `error` считает ошибку обработанной — исключение не бросается. Кастомный декод:

```php
$client->on('decode_response', function ($spec, &$result) {
    $result['data'] = my_custom_parse($result['body']); // заполнили — авто-декод пропущен
});
```

---

## Повторы запросов (requeue)

Слушатель `error` может вернуть запрос в очередь повторов, установив `$result['requeue'] = true`. Лимит задаётся конфигом `requeue`:

- `0` (по умолчанию) — повторы запрещены, флаг игнорируется;
- `<0` — без лимита;
- `>0` — максимум попыток на запрос.

Повторы дожёвываются **тем же конвейером** после основного потока — поэтому работают и `while (next())`, и один вызов `send()`.

```php
$client = WebClient::create('https://api/{id}', [
    'requeue' => 3,          // до 3 повторов на запрос
])->query(['id' => ['1', '2', '3']]);

$client->on('error', function ($spec, &$result) {
    if ($result['status'] === 429 || $result['status'] >= 500) {
        $result['requeue'] = true;   // повторить временную ошибку
    }
    // иначе — результат уйдёт потребителю как есть
});

$results = $client->send(); // упавшие повторяются в пределах лимита
```

Дренаж до полного исчерпания очереди (с внешним циклом, если нужно):

```php
while ($batch = $client->send()) {
    foreach ($batch as $r) { /* ... */ }
}
```

Если лимит исчерпан, флаг `requeue` игнорируется и ошибочный результат отдаётся потребителю (при наличии слушателя `error` исключения не будет).

При повторе события `response` для упавшей попытки не срабатывает, результат не выдаётся и не кешируется.

---

## Группы

`WebClient::group()` объединяет несколько клиентов в **общий конвейер** с единой очередью. Клиенты, созданные внутри замыкания (включая вложенные `group()`), сливаются в одну плоскую группу.

```php
WebClient::group(callable $fn, array $config = []): self
```

```php
$group = WebClient::group(function () {
    WebClient::create('https://api-a.com/{id}')->query(['id' => ['1', '2']]);
    WebClient::create('https://api-b.com/{id}', ['method' => 'post'])
        ->schema(['x' => [WebClient::param('x'), 'int']])
        ->query(['x' => [10, 20]]);
}, ['batch' => 5]);

// общий обработчик: различаем клиентов по url / config
while ($r = $group->next()) {
    switch (true) {
        case strpos($r['url'], 'api-a.com') !== false:
            handleA($r); break;
        case strpos($r['url'], 'api-b.com') !== false:
            handleB($r); break;
    }
}
```

**Поведение:**

- Задания идут в порядке создания клиентов; окна `batch` могут смешивать запросы разных клиентов на границах.
- `batch`/`delay` берутся из конфига **группы**; per-request настройки (метод, заголовки, таймаут, кеш) и события — от **клиента-владельца**.
- В результат каждого запроса кладётся `config` его клиента-владельца — для различения в общем обработчике.
- `next()`/`send()`/`reset()` группы действуют на всех участников.

### Каскад конфигурации

При создании клиента конфиг собирается по приоритету (побеждает более конкретный):

```
явный конфиг клиента  <-  конфиг внутренней группы  <-  ...  <-  внешней группы  <-  дефолты
```

```php
$group = WebClient::group(function () {
    WebClient::create('https://a/{i}', ['timeout' => 11.0])->query(['i' => ['1']]);
    //                              ^ клиент побеждает: timeout = 11

    WebClient::group(function () {
        WebClient::create('https://b/{i}')->query(['i' => ['1']]);
        //                          ^ наследует от вложенной группы: timeout = 22
    }, ['timeout' => 22.0]);
}, ['batch' => 3, 'timeout' => 33.0]); // внешняя группа: timeout = 33 (если ближе никто не задал)
```

Каскад идёт по «плоским» ключам: ассоциативные значения (например `headers` вида `['Authorization' => ...]`) сливаются поключево — недостающий ключ добирается из группы; списковые значения (`['Key: Value', ...]`) заменяются целиком.

### Иммутабельность группы

Объект группы нельзя настраивать как обычный клиент: `query()`, `schema()`, `fill()` бросают `LogicException`. События регистрируются **на клиентах-участниках**, а не на группе.

```php
$group->query([]);   // LogicException
$group->reset();     // OK — сбрасывает всех участников
```

---

## Кеширование

Кеш (`Cache\Manager`) работает только для `GET`/`HEAD` и выключен по умолчанию. Ключ = чистый URL + все параметры (из query string и тела).

```php
$client = WebClient::create('https://api/{id}', [
    'cache' => [
        'use'    => true,
        'ttl'    => 600,           // свежесть 10 минут
        'driver' => 'filesystem',
        'dir'    => '/var/cache/wc',
    ],
])->query(['id' => ['1', '2', '3']]);

$client->send();          // сеть -> кеш
$client->reset()->send(); // из кеша (cached => true), без сети
```

- Свежий кеш отдаётся без запроса; событие только `response`, `cached => true`.
- Протухший кеш ревалидируется через `If-None-Match`/`If-Modified-Since`; на `304` тело обрывается, TTL продлевается до `max(конфиг, max-age/Expires сервера)`.
- В кеш-блоб не пишутся `data` (несериализуема) и `config` (подставляется актуальный при чтении).

---

## Схема URL и verify

`verify` (по умолчанию `false`) управляет и SSL-проверками curl, и схемой URL, если она не указана:

| URL | `verify` | Результат |
|-----|----------|-----------|
| `host/path` (без схемы) | `false` | `http://host/path` |
| `host/path` (без схемы) | `true` | `https://host/path` |
| `http://...` | `true` | `InvalidArgumentException` |
| `https://...` | `false` | Валидно, но SSL-проверки не включаются |

```php
WebClient::create('example.com/api')->send();                    // http://example.com/api
WebClient::create('example.com/api', ['verify' => true])->send(); // https://example.com/api
```

---

## Обработка ошибок

Запрос считается ошибочным при `errno != 0` (ошибка curl) или `status >= 400`.

```php
// по умолчанию exception=true, без слушателя error -> RuntimeException
try {
    WebClient::create('https://api/404-endpoint')->send();
} catch (\RuntimeException $e) {
    echo $e->getMessage(); // WebClient: запрос '...' завершился ошибкой: HTTP 404
}

// подавить исключения слушателем (on() возвращает void — регистрируем отдельно)
$client = WebClient::create('https://api/{id}')->query(['id' => ['ok', 'missing']]);
$client->on('error', function ($spec, $result) {
    echo "Пропускаю {$result['url']}: {$result['status']}\n";
});
$client->send(); // без исключения, оба результата вернутся

// либо отключить исключения глобально для клиента
WebClient::create('https://api/x', ['exception' => false])->send();
```

Типы исключений:

| Исключение | Причина |
|------------|---------|
| `LogicException` | `query()` без шаблона/маркеров; незаполненные параметры; файлы через GET/HEAD; `query()/schema()/fill()` на группе. |
| `InvalidArgumentException` | Неизвестный параметр в `query()`; ошибка конфигурации маркера; `http://` при `verify=true`. |
| `RuntimeException` | Необработанная HTTP/curl-ошибка при `exception=true`. |
| (валидация) | Нарушение `schema()`/правил `query()` — бросает `Rule` (throwable). |

---

## Справочник публичного API

### Статические методы

```php
WebClient::create(string $url, array $config = []): self
// Создать клиента для одного (шаблонизированного) URL.

WebClient::group(callable $fn, array $config = []): self
// Собрать клиентов, созданных внутри $fn, в общий конвейер. Возвращает объект группы.

WebClient::param(string $name): object
// Маркер параметризуемого поля тела для schema(). Значения подаются через query().

WebClient::setConfig(array $config): void   // из HasConfig — глобальные дефолты
WebClient::config(string $key = '')         // из HasConfig — прочитать дефолт
```

### Наполнение запроса (текучий интерфейс, возвращают `self`)

```php
->query(array $params): self
// Значения плейсхолдеров URL и/или маркеров тела. Массивы -> декартово произведение.

->schema(array $schema): self
// Правила валидации тела (Rule::object). Поля с WebClient::param() параметризуются.
// Смена схемы обнуляет заполненное тело. Обязателен ДО query() с маркерными именами.

->fill(array $params): self
// Одно тело на всю пачку. CURLFile / '@путь' -> multipart. Валидируется против schema().
```

### Отправка

```php
->next(): ?array   // один результат; null после исчерпания (до reset()/изменения запроса)
->send(): array    // все результаты пачки; [] после полного прохода
->reset(): self    // полный перезапуск: забыть обработанные комбинации (у группы — все участники)
```

### События (из `HasEvents`)

```php
->on(string $event, callable $listener): void
// События: prepare, prepare_response, decode_response, error, response.

->trigger(string $event, &...$params)  // вызвать пользовательское (не зарезервированное) событие
```

### Структура результата

```php
[
    'status'        => int,
    'headers'       => array,   // lowercase-ключи
    'body'          => ?string,
    'data'          => ?Resource,
    'type'          => string,  // MIME
    'url'           => string,  // чистый URL
    'effective_url' => string,  // после редиректов
    'errno'         => int,
    'error'         => string,
    'aborted'       => bool,
    'cached'        => bool,
    'config'        => array,   // конфиг клиента-владельца
    'info'          => array,   // curl_getinfo()
]
```

### Контракты внутри событий

```php
// prepare: мутируйте $spec (url, get, method, headers, body, multipart, response_type, params)
$client->on('prepare', fn(&$spec) => $spec['headers']['X-Trace'] = uniqid());

// error: верните запрос в очередь (нужен config 'requeue' != 0)
$client->on('error', function ($spec, &$result) { $result['requeue'] = true; });

// decode_response: заполните $result['data'] сами, чтобы отменить авто-декод
$client->on('decode_response', function ($spec, &$result) { $result['data'] = parse($result['body']); });
```
