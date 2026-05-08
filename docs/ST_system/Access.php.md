# Access.php

> Для AI-агентов: этот документ описывает **всю** публичную поверхность класса `Access`.  
> Класс живёт в пространстве имён `ST_system`, файл `Access.php`.  
> При генерации кода используй эти примеры как шаблон.

---

## 1. Концепция

`Access` — **статический фасад контроля доступа**. Финальный класс (не наследуется), использует трейт `HasConfig`.

Группы задач:

1. **Аутентификация по паролю** — `requestAccess()`, `httpAccess()`, `call()`, `startBlock()` / `endBlock()`.
2. **HTTP-ответы** — `throw_403()`, `throw_404()`.
3. **Информация о запросе** — `getRequestOrigin()`, `getClientIp()`.
4. **CORS** — `handleCORS()`.

**Дефолтная конфигурация** (переопределяется через `Access::setConfig()`):

| Ключ | Значение | Описание |
|-----|---------|----------|
| `password_name` | `'pass'` | Имя POST/GET-параметра с паролем |
| `request_methods` | `['GET','POST',...]` | Разрешённые HTTP-методы для CORS |

---

## 2. Публичные методы

### `static requestAccess(array $PARAMS = []): mixed`

Проверяет пароль из запроса (GET/POST). Если пароль верный — вызывает `onSuccess`, иначе `onFail`.

| Ключ `$PARAMS` | Тип | Описание |
|----------------|-----|----------|
| `name` | `string` | Имя параметра (умолчание: `config('password_name')`) |
| `value` | `string` | Ожидаемый пароль (умолчание: `date('dm')`) |
| `onFail` | `Closure` | Вызывается при неверном пароле. Умолчание: редирект на `/` |
| `onSuccess` | `Closure` | Вызывается при верном пароле. Умолчание: возвращает `true` |

```php
Access::requestAccess([
    'name'  => 'key',
    'value' => 'secret123',
    'onFail' => fn() => http_response_code(403) ?: exit,
    'onSuccess' => fn() => 'ok',
]);
```

---

### `static httpAccess(array $PARAMS = []): void`

HTTP Basic Authentication. Если креденциалы не переданы или неверны 401 + `WWW-Authenticate` хедер.

| Ключ `$PARAMS` | Тип | Описание |
|----------------|-----|----------|
| `login` | `string` | Ожидаемый логин |
| `password` | `string` | Ожидаемый пароль |

```php
Access::httpAccess(['login' => 'admin', 'password' => 'secret']);
// если не прошла аутентификация — ответ 401 + exit
```

---

### `static call(callable $f, array $PARAMS = []): mixed`

Выполняет `$f` только если пароль в запросе факт соответствует ожидаемому.

```php
$result = Access::call(function() {
    return 'secret_data';
}, ['name' => 'token', 'value' => 'abc123']);
// $result === 'secret_data' если GET/POST['token'] === 'abc123'
// null если нет
```

---

### `static startBlock(array $PARAMS = []): void`

Начинает буферизацию вывода. Объединяется с `endBlock()`. Блок HTML будет отображён только при верном пароле.

```php
Access::startBlock(['name' => 'pass', 'value' => 'admin']);
?>
<h1>Секретный блок</h1>
<?php
Access::endBlock();
// выведет h1 только если GET/POST['pass'] === 'admin'
```

---

### `static endBlock(): void`

Завершает буферизованный блок. Если пароль верный — отправляет буферизованный HTML на вывод, иначе отбрасывает.

---

### `static throw_403(): void`

Отправляет HTTP 403 и завершает выполнение (через `Response::send()`). Добавляет заголовок `X-Content-Type-Options: nosniff`.

```php
if (!$user->isAdmin()) {
    Access::throw_403();
}
```

---

### `static throw_404(): void`

Отправляет HTTP 404 и завершает выполнение.

---

### `static getRequestOrigin(): string`

Возвращает Origin запроса (из заголовка `Origin` или хост `Referer`). Результат кэшируется.

**Возвращает:** `string` — `Origin` или хост из `Referer`, или `''`.

```php
$origin = Access::getRequestOrigin(); // 'https://example.com'
```

---

### `static getClientIp(): string`

Возвращает IP-адрес клиента. Учитывает заголовки `X-Forwarded-For` и `Client-Ip` (для прокси). Результат кэшируется.

**Возвращает:** `string` — IP-адрес.

```php
$ip = Access::getClientIp(); // '192.168.1.1'
```

---

### `static handleCORS(array $PARAMS = []): void`

Устанавливает CORS-заголовки и обрабатывает preflight-запросы `OPTIONS`. Если Origin запрещён или не разрешён — вызывается `throw_403()`.

| Ключ `$PARAMS` | Тип | Описание |
|----------------|-----|----------|
| `allowed_origins` | `string[]` | Разрешённые Origins. `['*']` разрешает все. Умолч: `['*']` |
| `forbidden_origins` | `string[]` | Запрещённые Origins. Умолч: `[]` |
| `methods` | `string[]` | Разрешённые HTTP-методы. Умолч: все из конфига |
| `headers` | `string[]` | Разрешённые заголовки. Умолч: `['Content-Type', 'Authorization', 'X-Requested-With']` |

```php
Access::handleCORS([
    'allowed_origins'  => ['https://app.example.com'],
    'forbidden_origins'=> ['https://evil.example.com'],
    'methods'          => ['GET', 'POST'],
]);
```
