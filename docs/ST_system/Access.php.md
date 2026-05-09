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

**Дефолтная конфигурация** (переопределяется через `Access::setConfig()`):

| Ключ | Значение | Описание |
|------|---------|----------|
| `credentials.name` | `'pass'` | Имя параметра с паролем |
| `credentials.password` | `date('dm')` | Ожидаемый пароль |
| `accessMethod` | `null` | Способ извлечения учётных данных (см. ниже) |
| `CORS.methods` | `['GET','POST',...]` | Разрешённые HTTP-методы для CORS |
| `CORS.headers` | `['Content-Type',...]` | Разрешённые заголовки для CORS |

---

## 2. Публичные методы

### `static on(string $event, callable $listener): void`

Регистрирует обработчик события. Обработчики накапливаются в singleton-инстансе.

Зарезервированное событие: `'throw'` — срабатывает при вызове `throw()` до отправки HTTP-ответа.

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
