# Access.php

## 1. Концепция

`Access` — **статический фасад контроля доступа**. Финальный класс (не наследуется), использует трейты `HasConfig` и `HasEvents`.

Внутри класс использует singleton (`getInstance()`), чтобы хранить state слушателей событий при полностью статическом публичном API.

Группы задач:

1. **Аутентификация по паролю** — `requestAccess()`, `httpAccess()`, `call()`, `startBlock()` / `endBlock()`.
2. **HTTP-ответы с событиями** — `throw(int $code)`, `on()`.
3. **Информация о запросе** — `getRequestOrigin()`, `getClientIp()` (с доверенными прокси).
4. **CORS** — `handleCORS()`.
5. **IP-фаервол (rate-limit / ban)** — `handleIp()`, `banIp()`, `unbanIp()`, `unbanAll()`.
6. **Списки allow/deny + антибот-эвристики** — конфиг-driven (`firewall.rules` / `firewall.screen` / `firewall.verifyBots`), применяются внутри `handleIp()`.
7. **Проверка ботов** — `verifyBot()` (reverse + forward DNS).
8. **Геолокация по IP** — `handleGeo()` + реестр драйверов (`ipinfo` / `sxgeo` / `geoip2`).

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
| `firewall.exclude` | `[]` | Список IP / подсетей (CIDR / префикс `192.168.1.`), которые фаервол пропускает без учёта |
| `firewall.proxies` | `[]` | Доверенные прокси `['CIDR' => '$_SERVER-ключ']` для `getClientIp()` (см. §2.2). Пусто → заголовкам доверяют без проверки (легаси) |
| `firewall.verifyBots` | `false` | В `handleIp()` пропускать проверенных ботов (`verifyBot()`) мимо всех проверок |
| `firewall.screen` | `[]` | Эвристики для `handleIp()`: `noUserAgent` / `noReferer` / `noLang` / `http2Only` (bool). Срабатывание → `throw(403)` + событие `'screen'` |
| `firewall.rules` | `[]` | Списки allow/deny: `[[action, type, pattern], ...]`; `type` ∈ `ip`/`cidr`/`country`/`lang`/`referer`/`ua`/`ptr`. `allow` приоритетнее `deny` (см. §2.3) |
| `bots` | `{Googlebot, yandex.com, Mail.RU_Bot, bingbot}` | Сигнатуры белых ботов для `verifyBot()`: `'подстрока UA' => ['суффикс PTR', ...]`. `'.'` в списке → доверять без forward-confirm |
| `handleGeo.drivers.default` | `'ipinfo'` | Драйвер геолокации по умолчанию для `handleGeo()` |
| `handleGeo.drivers.available` | `{ipinfo, sxgeo, geoip2}` | Реестр `короткое имя => класс` (по образцу `Cache\Manager`) |

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

Возвращает IP-адрес клиента (IPv6 нормализуется к полной форме). Результат кэшируется на запрос.

Поведение зависит от `firewall.proxies` (см. §2.2):
- **список пуст** (умолчание) — берётся первый IP из `X-Forwarded-For`, затем `Client-Ip`, затем `REMOTE_ADDR` (легаси, обратная совместимость);
- **список задан** — строгий режим: заголовку доверяют, только если `REMOTE_ADDR` входит в доверенную подсеть.

```php
$ip = Access::getClientIp(); // '192.168.1.1'
```

---

## 2.2. Доверенные прокси (защита от подмены IP)

Без настройки `getClientIp()` доверяет `X-Forwarded-For` вслепую — клиент может прислать любой IP
и обойти фаервол/гео-фильтры. `firewall.proxies` включает строгий режим: реальный IP берётся из
указанного заголовка **только если** `REMOTE_ADDR` — доверенный прокси.

Формат: `['CIDR или точный IP' => 'имя $_SERVER-ключа с реальным IP']`.

```php
// За CloudFlare — доверяем CF-Connecting-IP только от подсетей CF:
Access::setConfig([
    'firewall' => [
        'proxies' => [
            '173.245.48.0/20'  => 'HTTP_CF_CONNECTING_IP',
            '103.21.244.0/22'  => 'HTTP_CF_CONNECTING_IP',
            '141.101.64.0/18'  => 'HTTP_CF_CONNECTING_IP',
            '162.158.0.0/15'   => 'HTTP_CF_CONNECTING_IP',
            '104.16.0.0/13'    => 'HTTP_CF_CONNECTING_IP',
            // ... полный список подсетей CloudFlare
        ],
    ],
]);

// За nginx-реверс-прокси на том же хосте:
Access::setConfig(['firewall' => ['proxies' => ['127.0.0.1' => 'HTTP_X_REAL_IP']]]);

$ip = Access::getClientIp(); // реальный IP клиента, а не адрес прокси
```

Поддержка CIDR (IPv4 и IPv6) распространяется и на `firewall.exclude`, и на правила типа `ip`/`cidr`
(см. §4).

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

**Единая точка входа фаервола на запрос.** Полностью управляется конфигом — никаких отдельных
методов правил/эвристик (всё в `firewall.*`). Порядок проверок для `getClientIp()`:

1. IP не определён/невалиден → выход (мусорный `X-Forwarded-For` не раздувает кэш);
2. IP в `firewall.exclude` (IP / CIDR / префикс) → выход, без проверок;
3. `firewall.verifyBots = true` и `verifyBot()` подтвердил бота → выход (проверенные краулеры мимо всего);
4. `firewall.screen` — эвристики; при срабатывании событие `'screen'` + `throw(403)`;
5. `firewall.rules` — списки allow/deny (`allow` приоритетнее): `allow` → выход, `deny` → событие `'deny'` + `throw(403)`;
6. **rate-limit**: инкремент счётчиков по окнам `firewall.limits`; при превышении — бан (`banTtl` из 3-го элемента лимита либо `firewall.ttl`), событие `'ban'`, `throw(429)`.

Состояние IP лежит в одной зашифрованной записи кэша (см. §2.1).

```php
Access::setConfig([
    'salt'     => getenv('APP_SECRET'),
    'firewall' => [
        'limits'     => [[120, 60, 300], [2000, 3600]],
        'verifyBots' => true,                                  // Googlebot и Ко — мимо лимитов
        'screen'     => ['noUserAgent' => true, 'noLang' => true],
        'rules'      => [
            ['allow', 'ip',      '198.51.100.7'],
            ['deny',  'country', 'CN'],
            ['deny',  'cidr',    '203.0.113.0/24'],
            ['deny',  'ua',      'python-requests'],
        ],
    ],
]);

Access::handleIp(); // один вызов в начале обработки запроса делает всё вышеперечисленное
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

### `static unbanAll(): void`

Снимает все активные баны (очищает весь кэш фаервола). Поднимает событие `'unbanAll'`.

```php
Access::unbanAll(); // разбанить всех IP
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

## 2.3. Списки allow/deny и эвристики (config-driven)

Всё это — **не отдельные методы, а конфиг**, который применяет `handleIp()` (по образцу
`firewall.limits`). Не путать с `banIp()`: тот делает точечные динамические rate-limit баны по IP,
а `firewall.rules` — статические курируемые списки, проверяемые на каждом запросе.

### `firewall.rules` — allow / deny

Массив правил `[action, type, pattern]`:
- `action` — `'allow'` (пропустить мимо остальных проверок) или `'deny'` (`throw(403)` + событие `'deny'`);
- `type` — `ip`, `cidr`, `country`, `lang`, `referer`, `ua`, `ptr`;
- `pattern` — значение (для `ip`/`cidr` — точный IP, CIDR или префикс `192.168.1.`; для остальных — сравнение/подстрока).

`allow` имеет приоритет над `deny`. Контекст вычисляется автоматически: `country` — из заголовка
`HTTP_CF_IPCOUNTRY` (CloudFlare); `ptr` — reverse DNS (ленивый lookup, только если есть правило `ptr`).

```php
Access::setConfig(['firewall' => ['rules' => [
    ['allow', 'ip',      '198.51.100.7'],     // белый IP — мимо всего
    ['deny',  'country', 'CN'],               // блок страны
    ['deny',  'cidr',    '203.0.113.0/24'],   // блок подсети
    ['deny',  'referer', 'spam-site.example'],// блок по хосту реферера
    ['deny',  'ua',      'python-requests'],  // блок по подстроке UA
]]]);
```

### `firewall.screen` — эвристики «человечности»

Флаги (по умолчанию выключены); при срабатывании `handleIp()` поднимает событие `'screen'`
(`$reason`, `$ip`) и делает `throw(403)`:

| Флаг | Блокирует если |
|------|----------------|
| `noUserAgent` | пустой `User-Agent` |
| `noReferer` | пустой Origin/Referer |
| `noLang` | пустой или `*` `Accept-Language` |
| `http2Only` | `SERVER_PROTOCOL` не `HTTP/2.0` |

```php
Access::setConfig(['firewall' => ['screen' => [
    'noUserAgent' => true,
    'noLang'      => true,
]]]);
```

### `firewall.verifyBots`

`true` → в `handleIp()` проверенные поисковые боты (`verifyBot()`) пропускаются мимо эвристик,
правил и лимитов.

Все три ключа проверяются внутри `handleIp()` — отдельных вызовов не требуется.

---

## 2.4. Проверка ботов

### `static verifyBot(?array $signatures = null): bool`

Проверяет, что клиент — **настоящий** поисковый бот: сигнатура в `User-Agent` + reverse DNS (PTR)
с суффиксом из белого списка + forward-confirm (PTR резолвится обратно в тот же IP). Отличает
реального `Googlebot` от подделки с таким же UA. Результат кэшируется по `UA+IP` (1 час).
`$signatures` — умолчание из конфига `bots` (`'подстрока UA' => ['суффикс PTR', ...]`; `'.'` в
списке → доверять без forward-confirm). Поднимает событие `'verifiedBot'` (`$ip`, `$ua`) при успехе.

Обычно отдельно вызывать не нужно — включите `firewall.verifyBots = true`, и `handleIp()`
сам пропустит проверенных ботов. Прямой вызов полезен для собственной логики:

```php
// отдать ботам «чистую» версию страницы без cookie-баннера/лимитов:
if (Access::verifyBot()) {
    renderForCrawler();
} else {
    Access::handleIp();
}

// свой список ботов:
Access::verifyBot([
    'Googlebot'    => ['.googlebot.com'],
    'AhrefsBot'    => ['ahrefs.com'],
    'MyMonitoring' => ['.'],   // доверять без forward-confirm
]);
```

---

## 2.5. Геолокация по IP — `handleGeo()` + реестр драйверов

### `static handleGeo(array $PARAMS = []): mixed`

Определяет гео-данные по IP выбранным драйвером и применяет white/black списки по любому
нормализованному полю (`country`, `country_code`, `city`, ...).

| Ключ `$PARAMS` | Тип | Описание |
|----------------|-----|----------|
| `driver` | `string` | Короткое имя из `handleGeo.drivers.available` или FQCN. Умолч.: `handleGeo.drivers.default` (`ipinfo`) |
| `token` | `string` | Смысл зависит от драйвера: API-ключ (`ipinfo`, `sxgeo`) / `account_id:license_key` (`geoip2`). Для локальной БД можно опустить |
| `ip` | `string` | Умолч.: `getClientIp()` |
| `black_list` | `array` | `['поле' => значение\|[значения]]` → `onBlackList` |
| `white_list` | `array` | все поля должны совпасть → `onWhiteList` |
| `onBlackList` | `callable` | Умолч.: `throw(403)` |
| `onWhiteList` / `onPassed` / `onError` | `callable\|null` | колбэки |

Драйвер резолвится по реестру (по образцу `Cache\Manager`): короткое имя → класс; можно
передать и полное имя класса. Класс обязан реализовывать `ST_system\API\Drivers\Geo\GeoDriver`.

```php
// блокировка стран через локальную БД SxGeo (без API-ключа, оффлайн):
Access::handleGeo([
    'driver'     => 'sxgeo',
    'black_list' => ['country' => ['CN', 'KP']],
    'onBlackList'=> fn($d) => Access::throw(451),
]);

// пропускать только РФ/РБ через MaxMind GeoIP2 (локальный .mmdb):
Access::handleGeo([
    'driver'     => 'geoip2',
    'white_list' => ['country' => ['RU', 'BY']],
    'onWhiteList'=> fn($d) => true,
    'onBlackList'=> fn($d) => Access::throw(403),
]);

// глобально сменить провайдера по умолчанию:
Access::setConfig(['handleGeo.drivers.default' => 'geoip2']);
$details = Access::handleGeo(['token' => 'ACCOUNT:LICENSE']);
```

**Доступные драйверы** (`handleGeo.drivers.available`) — см. отдельные доки:

| Имя | Класс | Оффлайн-БД | REST API |
|-----|-------|-----------|----------|
| `ipinfo` | `Drivers\Geo\IpInfo` | — | ipinfo.io |
| `sxgeo` | `Drivers\Geo\SxGeo` | `.dat` (Sypex Geo) | api.sypexgeo.net |
| `geoip2` | `Drivers\Geo\GeoIP2` | `.mmdb` (MaxMind) | geoip.maxmind.com |

Все гео-драйверы наследуют абстрактный `Drivers\Geo\GeoDriver` и поддерживают режимы
`mode` = `auto` / `local` / `api`, а также `update()` (скачать/обновить локальную БД) и
`version()` (дата сборки БД). Подробности — в доках драйверов.

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
