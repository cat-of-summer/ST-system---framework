# Access.php

> Для AI-агентов: этот документ описывает **всю** публичную поверхность класса `Access`.  
> Класс живёт в пространстве имён `ST_system`, файл `Access.php`.  
> При генерации кода используй эти примеры как шаблон.

---

## 1. Концепция

`Access` — **статический фасад контроля доступа**. Финальный класс (не наследуется), использует трейты `HasConfig` и `HasEvents`.

Внутри класс использует singleton (`getInstance()`), чтобы хранить state слушателей событий при полностью статическом публичном API.

Группы задач:

1. **Аутентификация по паролю** — `requestAccess()`, `httpAccess()`, `call()`, `startBlock()` / `endBlock()`.
2. **HTTP-ответы с событиями** — `throw(int $code)`, `on()`.
3. **Информация о запросе** — `getRequestOrigin()`, `getClientIp()`.
4. **CORS** — `handleCORS()`.
5. **IP-фаервол (rate-limit / ban)** — `handleIp()`, `banIp()`, `unbanIp()`.

Внутри singleton-инстанса в конструкторе создаётся объект `ST_system\Cache\Manager` (драйвер берётся
из `config('cache.driver')`), на котором держится состояние IP-фаервола. Поэтому `Access::setConfig(...)`
должен вызываться **до первого обращения** к любому методу `Access` (как и для остальных трейтовых конфигов).

**Дефолтная конфигурация** (переопределяется через `Access::setConfig()`):

| Ключ | Значение | Описание |
|------|---------|----------|
| `credentials.name` | `'pass'` | Имя параметра с паролем |
| `credentials.password` | `date('dm')` | Ожидаемый пароль |
| `accessMethod` | `null` | Способ извлечения учётных данных (см. ниже) |
| `CORS.methods` | `['GET','POST',...]` | Разрешённые HTTP-методы для CORS |
| `CORS.headers` | `['Content-Type',...]` | Разрешённые заголовки для CORS |
| `cache.driver` | `'session'` | Драйвер `Cache\Manager` для IP-фаервола (`filesystem`/`redis`/`database`/...). Невалидный → фолбэк на драйвер по умолчанию |
| `cache.ttl` | `false` | TTL по умолчанию для записей кэша (фаервол всё равно ставит свой TTL на запись) |
| `salt` | `''` | Секрет: солит ключи кэша по IP и шифрует хранимые данные. Если пустой — данные фаервола хранятся в открытом виде (без шифрования) |
| `firewall.limits` | `[[60,60],[600,3600]]` | Список `[maxHits, windowSeconds]` или `[maxHits, windowSeconds, banTtl]` — лимит обращений с одного IP в окне; `banTtl` — длительность бана при срабатывании этого лимита (сек.), если не задан — используется `firewall.ttl` |
| `firewall.ttl` | `3600` | Сколько секунд IP остаётся в бане после превышения лимита (глобальный fallback) |
| `firewall.banCode` | `429` | HTTP-код, который отдаёт `Access::throw()` забаненному IP |

---

## 2. Публичные методы

### `static on(string $event, callable $listener): void`

Регистрирует обработчик события. Обработчики накапливаются в singleton-инстансе.

События:
- `'throw'` — срабатывает при вызове `throw()` до отправки HTTP-ответа (передаётся `int $code`).
- `'ban'` — IP забанен (`handleIp()` при превышении лимита либо `banIp()`); передаётся `string $ip`.
- `'unban'` — `unbanIp()` снял бан; передаётся `string $ip`.

```php
// Перехват 404 — redirect вместо HTTP 404
Access::on('throw', function(int $code) {
    if ($code === 404) {
        header('Location: /not-found');
        exit;
    }
});

// Логирование всех ошибок доступа
Access::on('throw', function(int $code) {
    error_log("Access denied: HTTP $code");
    // не делаем exit — throw() продолжит обработку по умолчанию
});

// Аудит банов
Access::on('ban',   fn(string $ip) => error_log("IP banned: $ip"));
Access::on('unban', fn(string $ip) => error_log("IP unbanned: $ip"));
```

---

### `static throw(int $code = 404): void`

Отправляет HTTP-ответ с кодом `$code` и завершает выполнение.

**Логика с событиями:**
- Сначала вызывает `fire('throw', $code)` — запускает всех зарегистрированных слушателей.
- Если слушателей нет (`fire` возвращает `false`) — сам отправляет `Response::status($code)` + `X-Content-Type-Options: nosniff` + exit.
- Если слушатель выполнил `exit` сам — HTTP-ответ не дублируется.

```php
Access::throw(403); // fire('throw', 403) → если нет обработчиков → HTTP 403 + exit
Access::throw();    // по умолчанию 404
```

---

### `static requestAccess(array $PARAMS = []): mixed`

Проверяет учётные данные из запроса. Если верные — вызывает `onSuccess`, иначе `onFail`.

| Ключ `$PARAMS` | Тип | Описание |
|----------------|-----|----------|
| `name` | `string` | Имя параметра (умолчание: `credentials.name`) |
| `value` | `string` | Ожидаемый пароль (умолчание: `credentials.password`) |
| `accessMethod` | `string\|null` | Способ получения данных (умолчание: `config('accessMethod')`) |
| `onFail` | `Closure` | Вызывается при неверных данных. Умолчание: `throw(401)` |
| `onSuccess` | `Closure\|null` | Вызывается при верных данных |

```php
Access::requestAccess([
    'name'         => 'key',
    'value'        => 'secret123',
    'accessMethod' => 'POST',
    'onFail'       => fn() => Access::throw(403),
    'onSuccess'    => fn() => 'ok',
]);

// Bearer token
Access::requestAccess([
    'accessMethod' => 'HEADERS',
    'name'         => 'Bearer',
    'value'        => 'my-api-token',
]);
```

---

### `static httpAccess(array $PARAMS = []): void`

HTTP Basic Authentication через `$_SERVER['PHP_AUTH_USER']` / `PHP_AUTH_PW`. Если не прошла — 401 + `WWW-Authenticate` + exit.

| Ключ `$PARAMS` | Тип | Описание |
|----------------|-----|----------|
| `login` | `string` | Ожидаемый логин |
| `password` | `string` | Ожидаемый пароль |

```php
Access::httpAccess(['login' => 'admin', 'password' => 'secret']);
```

---

### `static call(callable $f, array $PARAMS = []): mixed`

Выполняет `$f` только если учётные данные верные.

| Ключ `$PARAMS` | Тип | Описание |
|----------------|-----|----------|
| `name` | `string` | Имя параметра |
| `value` | `string` | Ожидаемое значение |
| `accessMethod` | `string\|null` | Способ получения данных |

```php
$result = Access::call(function() {
    return 'secret_data';
}, ['name' => 'token', 'value' => 'abc123', 'accessMethod' => 'GET']);
```

---

### `static startBlock(array $PARAMS = []): void`

Начинает буферизацию вывода. Объединяется с `endBlock()`. Блок HTML будет отображён только при верных учётных данных.

```php
Access::startBlock(['name' => 'pass', 'value' => 'admin', 'accessMethod' => 'POST']);
?>
<h1>Секретный блок</h1>
<?php
Access::endBlock();
```

---

### `static endBlock(): void`

Завершает буферизованный блок. Если данные верные — отправляет HTML на вывод, иначе отбрасывает.

---

### `static getRequestOrigin(): string`

Возвращает Origin запроса (из заголовка `Origin` или хост `Referer`). Результат кэшируется.

**Возвращает:** `string` — `Origin` или хост из `Referer`, или `''`.

```php
$origin = Access::getRequestOrigin(); // 'https://example.com'
```

---

### `static getClientIp(): string`

Возвращает IP-адрес клиента. Учитывает заголовки `X-Forwarded-For` и `Client-Ip`. Результат кэшируется.

```php
$ip = Access::getClientIp(); // '192.168.1.1'
```

---

## 2.1. IP-фаервол (rate-limit / ban)

Защита от слишком частых обращений с одного IP. Состояние каждого IP лежит в **одной** записи
`Cache\Manager` (1 чтение + 1 запись на запрос). IP **не хранится в открытом виде**: ключ кэша —
солёный хэш IP (`hash('sha256', salt.'|'.ip)`), а полезная нагрузка (счётчики окон + метка бана)
шифруется быстрым XOR-keystream от соли и снабжается 8-байтным HMAC-тегом (подделать содержимое
кэша без `salt` нельзя; повреждённая запись трактуется как «нет состояния»).

**Рекомендации:**
- Для высоконагруженных проектов: `Access::setConfig(['cache' => ['driver' => 'redis']])` —
  файловый драйвер делает несколько файловых операций на запрос.
- Известное ограничение: read-modify-write без атомарности — при гонке параллельных запросов
  с одного IP возможен недосчёт на пару хитов (для защиты от абьюза приемлемо).

```php
Access::setConfig([
    'salt'     => getenv('APP_SECRET'),
    'firewall' => [
        'limits'  => [[120, 60, 300], [2000, 3600]], // ≤120/мин → бан 5 мин; ≤2000/час → бан firewall.ttl
        'ttl'     => 1800
    ],
]);

// в бутстрапе, на каждом запросе:
Access::handleIp();
```

---

### `static handleIp(): void`

Учитывает текущее обращение клиента (`getClientIp()`):
1. если IP уже забанен и бан не истёк — `Access::throw(429)`;
2. иначе инкрементирует счётчики по всем окнам `firewall.limits` (фиксированные окна `floor(time()/window)`);
3. если хоть один лимит превышен — ставит бан; длительность бана берётся из третьего элемента сработавшего лимита (`[maxHits, windowSeconds, banTtl]`), или из `firewall.ttl` если он не задан; при срабатывании нескольких лимитов одновременно берётся максимальный `banTtl`; поднимает событие `'ban'` и вызывает `Access::throw(429)`.

Если IP не определён или невалиден (`filter_var(... FILTER_VALIDATE_IP)`), метод ничего не делает
(чтобы поток мусорных `X-Forwarded-For` не раздувал кэш).

```php
Access::handleIp(); // обычно один раз в начале обработки запроса
```

---

### `static banIp(string $ip, ?int $ttl = null): void`

Принудительно банит IP. `$ttl` (сек.) — длительность бана; `null` → `config('firewall.ttl')`.
Поднимает событие `'ban'`. Невалидный IP → `\InvalidArgumentException`.

```php
Access::banIp('203.0.113.7');          // на firewall.ttl
Access::banIp('203.0.113.7', 86400);   // на сутки
```

---

### `static unbanIp(string $ip): void`

Снимает бан с IP (счётчики окон сохраняются). Если по IP больше нет данных — запись кэша удаляется.
Поднимает событие `'unban'`. Невалидный IP → `\InvalidArgumentException`.

```php
Access::unbanIp('203.0.113.7');
```

---

### `static handleCORS(array $PARAMS = []): void`

Устанавливает CORS-заголовки и обрабатывает preflight-запросы `OPTIONS`. Если Origin запрещён или не разрешён — вызывается `throw(403)`.

| Ключ `$PARAMS` | Тип | Описание |
|----------------|-----|----------|
| `allowed_origins` | `string[]` | Разрешённые Origins. `['*']` разрешает все. Умолч: `['*']` |
| `forbidden_origins` | `string[]` | Запрещённые Origins. Умолч: `[]` |
| `methods` | `string[]` | Разрешённые HTTP-методы. Умолч: из конфига |
| `headers` | `string[]` | Разрешённые заголовки. Умолч: из конфига |

```php
Access::handleCORS([
    'allowed_origins'   => ['https://app.example.com'],
    'forbidden_origins' => ['https://evil.example.com'],
    'methods'           => ['GET', 'POST'],
]);
```

---

## 3. accessMethod — способы получения учётных данных

Применяется в `requestAccess()`, `call()`, `startBlock()`. Задаётся глобально через `setConfig()` или локально в `$PARAMS`.

| Значение | Источник данных |
|----------|----------------|
| `null` (умолч.) | `$_REQUEST[$name]` |
| `'GET'` | `$_GET[$name]` |
| `'POST'` | `$_POST[$name]` |
| `'PUT'` / `'DELETE'` / `'PATCH'` | тело запроса (`php://input`, form-encoded) |
| `'HEADERS'` | заголовки (см. ниже) |
| `'COOKIE'` | `$_COOKIE[$name]` |
| `'SESSION'` | `$_SESSION[$name]` |

### HEADERS — детали

`name` — имя HTTP-заголовка, передаётся напрямую в `Request::headers($name)`.

| `name` | Что читается |
|--------|-------------|
| `'Authorization'` | заголовок `Authorization` целиком (`"Bearer token"`, `"Basic base64"`) |
| `'X-Api-Key'` | заголовок `X-Api-Key` |
| `'X-Signature'` | заголовок `X-Signature` |
| любое другое | `Request::headers($name)` |

```php
// Bearer token (value = полная строка заголовка)
Access::requestAccess([
    'accessMethod' => 'HEADERS',
    'name'         => 'Authorization',
    'value'        => 'Bearer my-api-token',
]);

// API Key
Access::requestAccess([
    'accessMethod' => 'HEADERS',
    'name'         => 'X-Api-Key',
    'value'        => 'key-12345',
]);

// Глобальный дефолт для всего приложения
Access::setConfig(['accessMethod' => 'HEADERS']);
Access::setConfig(['accessMethod' => 'POST']);
```
