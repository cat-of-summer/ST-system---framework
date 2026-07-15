<!-- DOCGEN:START -->
# IntegrationDriver.php
<!-- DOCGEN:END -->

`abstract class IntegrationDriver` (`ST_system\API\IntegrationDriver`) — базовый класс для построения типизированных обёрток над сторонними REST API. Это фундамент **всех** интеграционных драйверов в проекте (`Drivers/Acquiring/CloudPayments`, `Robokassa`, `TBank`, `Drivers/AI/Mistral`, `Drivers/Bots/MaxBot`, `TelegramBot`, `VkBot`, `Drivers/CRM/Bitrix24`, `RentalCRM`, `Drivers/Geo/GeoIP2`, `IpInfo`, `SxGeo`, `Drivers/Isdayoff`, `Drivers/Parsers/DefaultParser` + `Prodoctorov`, `Drivers/Sdek`, `Drivers/SmartCaptcha`, `Drivers/SmsRu`, `Drivers/Telegraph` и т.д. — десятки конкретных подклассов). Идея: вместо ручного написания HTTP-кода на каждый эндпоинт стороннего API, подкласс декларативно **регистрирует "методы"** (`registerMethod`) — шаблоны эндпоинтов с `{placeholders}`, схемой валидации параметров, HTTP-методом, TTL кеша и т.д. — а `IntegrationDriver` превращает эту декларацию в реальный HTTP-пайплайн: валидация → построение URL → запрос → кеш → разбор ответа, с точками расширения на каждом шаге через событийную систему.

Использует трейты `HasConfig` (конфиг класса с dot-путями, `static::config()`) и `HasEvents` (`on()`/`fire()`/`trigger()`, зарезервированные события). Зависит от `ST_system\Rule` (валидация и скоуп алиасов правил), `ST_system\Main` (`glue()`, `basename()` — путь кеша по умолчанию), [`ST_system\HTTP\WebClient`](../HTTP/WebClient.php.md) (сам HTTP-транспорт, одиночные и конкурентные запросы через `WebClient::group()`), [`ST_system\Cache\CacheManager`](../Cache/CacheManager.php.md) (кеширование как инстанса драйвера в целом, так и отдельных ответов вызовов).

## Конфиг по умолчанию

```php
[
    'endpoint'        => '',   // базовый URL стороннего API; переопределяется в __init() подкласса
    'timeout'         => 30.0,
    'connect_timeout' => 10.0,
    'verify'          => true, // проверка SSL-сертификата
    'requeue'         => 0,    // сколько раз WebClient повторит запрос при сетевой ошибке/5xx (0 = выключено)
    'batch'           => 10,   // размер конкурентного батча для callMany()
    'delay'           => 0,    // задержка между батчами callMany(), мс
    'cache' => [
        'dir'    => Main::glue([CacheManager::config('default.dir'), Main::basename(static::class)], '/'),
        'use'    => false,          // включает instance-level кеш ($this->cache)
        'driver' => 'filesystem',
    ],
]
```

Каталог кеша по умолчанию — `<дефолтная директория CacheManager>/<basename класса-подкласса>`, т.е. у каждого драйвера (`CloudPayments`, `Mistral` и т.п.) свой изолированный поднамespace на файловой системе.

## Зарезервированные события

`__construct`, `before_curl_init`, `encode_request`, `before_call`, `call`, `build_url`, `prepare_response`, `curl_error`, `decode_response`, `response`, `save_cache` — это lifecycle-хуки самого `IntegrationDriver`. На них можно подписаться через `$this->on('имя_события', ...)` (обычно внутри `__init()` подкласса) или через `on()`, вызванный извне на готовом инстансе, чтобы перехватить/расширить почти любую стадию запроса без переопределения кода базового класса — потому что каждая стадия реализована как `fire()`-вызов, чьё поведение форкается в зависимости от того, обработал ли его хоть один слушатель.

## Жизненный цикл: `create()` / `__construct()` / `__init()`

Конструктор объявлен `final`, поэтому конкретные драйверы не переопределяют `__construct` напрямую — вместо этого они переопределяют защищённый хук `__init()`:

```php
final class CloudPayments extends IntegrationDriver {
    protected function __init(): void {
        // конфиг доступен как static::config(...) / $this->config(...)
        $this->registerMethodsMap([
            'payments/cards/charge' => [
                'method'     => 'POST',
                'params'     => [
                    'Amount'   => Rule::create(fn(&$v) => is_numeric($v)),
                    'Currency' => Rule::create(fn(&$v) => is_string($v))->default('RUB'),
                ],
                'cache_ttl'  => 0,
            ],
            'payments/{id}' => ['method' => 'GET', 'cache_ttl' => 60],
        ]);

        $this->on('encode_request', function ($url, $method, &$params, &$config) {
            $params = json_encode($params);
            $config['content_type'] = 'application/json';
        });
    }
}
```

Точка входа для создания инстанса — `static::create(...$params)` (`final`, просто `new static(...$params)`), а не `new` напрямую — так подклассы с нетривиальными конструкторами (принимающими, например, `login`/`password` или API-ключ) остаются совместимы с общим API фабрики.

Что происходит в `__construct(...$args)`:

1. `Rule::scope(static::class, fn() => $this->__init())` — вызывает `__init()` внутри скоупа правил, привязанного к конкретному классу драйвера. Благодаря этому алиасы `Rule`, зарегистрированные подклассом внутри `__init()` (например, общие правила валидации телефона/email), не "утекают" в другие драйверы — они видны только внутри вызовов этого класса.
2. Если `config('cache.use') === true`, строится instance-level `$this->cache` через `CacheManager::make([static::class, ...$args], static::config('cache'))` — ключ кеша включает **и** класс, **и** аргументы конструктора, так что два инстанса одного драйвера с разными учётными данными (например, два разных Bitrix24-портала) не делят кеш.
3. Файрится событие `__construct` со всеми аргументами конструктора.

## `getEndpoint()` — базовый URL

`protected function getEndpoint(): string` возвращает `(string) static::config('endpoint')`. Переопределяется в подклассах, если базовый URL нужно вычислять динамически (например, зависит от региона/окружения), а не просто брать из конфига.

## Кеш инстанса: `cache()`, `purge()`, `purgeBase()`

- `cache(): ?CacheManager` (`final protected`) — аксессор instance-level кеша, `null`, если `cache.use` выключен.
- `purge(): void` (`public`, **не** `final`) — чистит текущую запись instance-кеша, если он есть. Подклассы могут переопределить, чтобы почистить что-то ещё дополнительно (например, локальный in-memory кеш токена авторизации).
- `purgeBase(): void` (`final public`) — чистит **весь** namespace кеша для этого класса целиком (все файлы/ключи под `cache.dir` этого драйвера), а не только текущую запись.

## `methodConfig()` — интроспекция карты методов

`final protected function methodConfig(string $method = ''): array` — без аргумента возвращает всю `$methods_map` целиком, с именем метода — конфиг конкретного зарегистрированного метода (или `[]`, если такого нет). Полезно, например, во внутренних хелперах подкласса, которым нужно посмотреть на `cache_ttl` или `meta` метода без выполнения запроса.

## Регистрация методов: `registerMethod()` и DSL для `{placeholders}`

`final protected function registerMethod(string $method, $config = []): self` — сердце декларативного API. `$method` — это строка вида `"users/{id}/posts"`, где `{...}` — именованные плейсхолдеры, которые позже либо подставляются параметрами вызова, либо (если параметр не указан явно вызывающим) резолвятся через route-matching (см. `call()`).

**Обнаружение дублей.** Перед регистрацией имя метода нормализуется — `normalize_method()` сворачивает **любой** `{...}` в одинаковый маркер `{%}` (`preg_replace('/\{[^}]+\}/', '{%}', $method)`). Из-за этого `"users/{id}"` и `"users/{slug}"` нормализуются в одну и ту же строку `"users/{%}"`, и повторная регистрация бросает исключение "Метод уже зарегистрирован" — то есть плейсхолдеры не участвуют в определении уникальности метода, важна только "форма" пути.

**`$config` как `\Closure`.** Если вместо массива передан `\Closure`, он сохраняется как есть без какой-либо нормализации. Такой "метод" при вызове (`call()`/`callMany()`) выполняется напрямую как замыкание с переданными параметрами — полностью минуя HTTP-транспорт, валидацию и кеш. Удобно для локальных/вычисляемых "псевдо-методов" драйвера (например, метод, который просто комбинирует результаты двух реальных вызовов, или чисто локальную бизнес-логику, которую хочется вызывать через тот же единый интерфейс `call()`).

**`$config` как массив.** Нормализуется в предсказуемую форму:

- `endpoint` — если валидный абсолютный URL, используется как есть, иначе фоллбэк на `getEndpoint()` этого драйвера;
- `headers` — массив (по умолчанию `[]`);
- `content_type` — строка, по умолчанию `application/x-www-form-urlencoded`;
- `method` — приводится к верхнему регистру, допустимы только `GET`/`POST` (иначе — `GET`);
- `params` — авто-построенная схема `Rule`. Каждый `{placeholder}`, найденный в строке метода, **автоматически** получает правило, если вызывающий (регистрирующий метод) сам его не задал:
  ```php
  Rule::create(fn(&$v) => is_string($v) && $v !== '')
      ->handleError(fn($v) => "Не передан обязательный параметр {$param_name}!")
      ->after(fn(&$v) => $v = trim($v, '/\\'))
      ->skip(true);
  ```
  То есть по умолчанию плейсхолдер обязателен, должен быть непустой строкой, автоматически подрезается от слэшей по краям, и `skip(true)` — не создаёт "дыр" в остальной валидации, если сам параметр отсутствует (типичный паттерн `Rule` для опциональных-но-подготавливаемых полей). После сбора всех правил (явных + авто-сгенерированных для плейсхолдеров) весь `params` целиком оборачивается в `Rule::scope(static::class, fn() => Rule::object($config['params']))`, то есть валидационная схема метода — это готовый `Rule`-объект, а не сырой массив;
- `on_prepare` — `callable`, вызываемый после валидации параметров (если задан), иначе `null`;
- `cache_ttl` — целое число, по умолчанию `0` (без кеша); `-1` — специальный сентинел "кешировать бесконечно" (без авто-истечения);
- `meta` — произвольный массив метаданных метода;
- `timeout` / `connect_timeout` / `verify` / `requeue` — каждый берётся из `$config`, если задан корректным типом, иначе фоллбэк на class-level конфиг того же драйвера (`static::config(...)`).

```php
$this->registerMethod('users/{id}/posts', [
    'method'    => 'GET',
    'cache_ttl' => 300,
    'params'    => [
        'limit' => Rule::create(fn(&$v) => is_int($v))->default(20),
    ],
]);

// позже:
$this->call('users/{id}/posts', ['id' => 42, 'limit' => 10]);
// или, благодаря route-matching:
$this->call('users/42/posts', ['limit' => 10]);
```

Массовые/обратные операции: `unregisterMethod(string $method)`, `registerMethodsMap(array $methods)` (вызывает `registerMethod` для каждой пары ключ/значение), `unregisterMethodsMap(array $methods)` — все `final protected`.

## Построение URL: `build_url()`

`final protected function build_url(string $method, string $endpoint = ''): array` возвращает `[$request_url, $endpoint]`. Логика выбора базового `endpoint`: переданный аргумент, если это валидный абсолютный URL; иначе `endpoint`, сохранённый при регистрации метода; иначе `getEndpoint()` драйвера. Дальше `endpoint` и `method` склеиваются через `/`, из результата убирается query-string, схлопываются дублирующиеся слэши (`#/{2,}#` → `/`) — но временно без протокола, чтобы не превратить `https://` в `https:/`. Файрится событие `build_url` (в `prepareRequest()` слушатели этого события получают ссылки на `$request_url`/`$endpoint`/`$method`/`$params` и могут их скорректировать перед финальной валидацией URL).

## HTTP-запрос: `request()`, `buildClient()`, `attachRetry()`, `mapResult()`

- `request(string $url, string $method, array $params, array $config): array` (`final protected`) — строит `WebClient` через `buildClient()`, заполняет его параметрами (`$client->fill($params)`), отправляет (`$client->send()`) и мапит первый результат через `mapResult()`.
- `buildClient(...)` (`private`) — определяет HTTP-метод в верхнем регистре, заголовки. Файрит `encode_request($url, $method, &$params, &$config)`:
  - если **никто не обработал** событие (`fire() === false`) — заголовок `Content-Type` ставится из `$config['content_type']`;
  - если событие **обработано** слушателем, но `content_type` в конфиге всё ещё не пуст — заголовок **всё равно** ставится. То есть подписчик, который, например, сам сериализует `$params` в JSON, должен явно очистить `$config['content_type']`, если хочет полностью взять на себя управление заголовком — простого факта обработки события недостаточно.
  Если `$params` — строка, она разбирается через `parse_str()` в массив. Затем создаётся `WebClient::create($url, [...])` с `exception => false` (`IntegrationDriver` сам обрабатывает HTTP/curl-ошибки через `processResponse()`, поэтому не хочет, чтобы `WebClient` бросал исключения на неудачный запрос) и подключается `attachRetry()`.
- `attachRetry($client, $requeue)` (`private`) — если `$requeue !== 0`, вешает на `WebClient` слушатель события `error`, который проставляет `$result['requeue'] = true` при сетевой ошибке (`errno !== 0`) или HTTP 5xx (`status >= 500`) — это активирует встроенный в `WebClient` механизм повторной постановки запроса в очередь на настроенное драйвером число попыток.
- `mapResult(?array $r, string $url): array` (`private`) — нормализует сырой результат `WebClient` в единый формат `['response', 'error', 'http_code', 'effective_url']`. `null` (например, полностью пустой ответ от `WebClient`) превращается в запись-ошибку с текстом `'Пустой ответ'`.

## Разбор ответа: `processResponse()`

`final protected function processResponse(string $method, array $params, array &$raw_data)`:

1. Файрит `prepare_response($method, $params, &$raw_data)` — точка для предобработки сырых данных до проверки на ошибку.
2. Если `$raw_data['error']` не пусто — файрит `curl_error($method, $params, &$raw_data)`. Если **не обработано** ни одним слушателем — бросает `\Exception` с текстом вида `"Ошибка при запросе '{$method}' к API: '{$error}' в {класс}"`. Если **обработано** — метод возвращает `false` (сигнал "тихого" провала, который дальше пробрасывается вверх из `call()`/`callMany()` без исключения).
3. Иначе файрит `decode_response($method, $params, &$raw_data)`:
   - если **не обработано** — тело ответа декодируется как JSON (`json_decode($raw_data['response'], true)`), и при ошибке декодирования бросается `\Exception` с `json_last_error_msg()`;
   - если **обработано** — используется `$raw_data['response']` как есть, то есть подписчик сам заранее декодировал (или подменил) значение (например, для API, отдающего XML или бинарный формат вместо JSON).
4. Файрит `response($method, $params, $decoded_value)` и возвращает `$decoded_value`.

## Route-matching: `resolveMethodConfig()`

`private function resolveMethodConfig(string &$method, array &$params)` — если буквальная строка `$method` не зарегистрирована напрямую, пытается сопоставить её со всеми зарегистрированными шаблонами `{param}` по количеству сегментов пути и посегментному совпадению статичных частей, попутно извлекая именованные захваты в `$captures`. При совпадении `$method` переписывается на подошедший шаблон, а `$params = array_merge($captures, $params)` — то есть значения, явно переданные вызывающим в `$params`, **побеждают** одноимённые захваты из самого пути. Если ни один шаблон не подошёл — бросает `\Exception` `"Метод '{$method}' не зарегистрирован в {класс}"`.

```php
// зарегистрировано: 'users/{id}/posts'
$driver->call('users/42/posts');
// resolveMethodConfig извлечёт id=42 автоматически, метод внутри станет 'users/{id}/posts'
```

## Подготовка запроса: `prepareRequest()`

`private function prepareRequest(string $method, array $params, array $config): array`:

1. Файрит `before_call($method, $params)`.
2. Внутри `Rule::scope(static::class, ...)` применяет схему `params` метода к переданным аргументам (через `Rule::object(...)->apply()`, либо напрямую `->apply()`, если `params` уже готовый `Rule`-объект). При первой ошибке валидации бросает `\InvalidArgumentException` с текстом этой ошибки. Затем, если задан `on_prepare`, вызывает его с (уже провалидированными) параметрами.
3. Убирает из `$params` все `null`-значения (`array_filter`).
4. Файрит `call($method, $params)`.
5. Подставляет оставшиеся `{param}` плейсхолдеры прямо в тело строки метода, потребляя (`unset`) соответствующие ключи из `$params`; если для плейсхолдера параметра не нашлось — бросает `\Exception` `"Не передан обязательный параметр {$name}!"`.
6. Строит финальный URL через `build_url()`, файрит `build_url($request_url, $endpoint, $method, $params)` по ссылкам — слушатели могут скорректировать любое из этих значений до отправки запроса.
7. Валидирует итоговый `$request_url` через `filter_var(..., FILTER_VALIDATE_URL)`; при провале бросает `\Exception`.
8. Файрит `before_curl_init($request_url, $method, $params, $config)`.
9. Возвращает план запроса: `['method', 'url', 'params', 'config']`.

## `call()` — одиночный вызов

`final public function call(string $method, array $params = [])` — главная точка входа:

1. `resolveMethodConfig()` резолвит конфиг метода (с route-matching при необходимости).
2. Если конфиг — `\Closure`, вызывает его напрямую с `$params` и возвращает результат, полностью минуя всё остальное (HTTP, валидацию, кеш).
3. `prepareRequest()` строит план запроса.
4. **Кеш ответа.** Если `cache_ttl > 0` **или** `cache_ttl === -1` (кешировать бесконечно), и у инстанса есть `$this->cache`, строится **per-call** `CacheManager` с ключом `[$url, $params]` и этим TTL. Если он `isValid()` и в нём реально что-то есть — используется как `$raw_data` (реальный HTTP-запрос пропускается), помечается `$from_cache = true`.
5. Если данных из кеша нет — выполняется настоящий `request()`.
6. `processResponse()` декодирует/валидирует `$raw_data`; если она вернула `false` (curl-ошибка, обработанная слушателем `curl_error`) — `call()` тоже возвращает `false`.
7. Если объект per-call кеша есть и это **не** был cache-hit — файрится `save_cache($method, $params, $response, &$meta)` (`$meta = ['ttl' => 0]` изначально; слушатель может переопределить `$meta['ttl']`), затем `json_encode($raw_data)` сохраняется в кеш с эффективным TTL — `$meta['ttl']` от слушателя, если он его задал, иначе исходный `cache_ttl` метода.
8. Возвращает декодированный ответ.

```php
$driver = CloudPayments::create($publicId, $secret);
$response = $driver->call('payments/{id}', ['id' => 123]); // GET с кешем на cache_ttl секунд
```

## `callMany()` — конкурентный батч вызовов

`final public function callMany(array $calls, array $opts = []): array` — та же семантика `call()`, но для набора вызовов, отправляемых **конкурентно** через один общий curl_multi-пайплайн `WebClient::group()`.

1. Каждый элемент `$calls` нормализуется через `normalizeCallSpec()` (см. ниже) в пару `[method, params]`.
2. Для каждого резолвится конфиг метода. Замыкания (`\Closure`-методы) выполняются **сразу и инлайново** — их результат сразу же кладётся в соответствующий слот `$results[$i]`, они не участвуют в конкурентной группе запросов.
3. Остальные вызовы превращаются в план запроса через `prepareRequest()` и складываются в `$plans[$i]`.
4. Если нет ни одного не-closure плана — возвращается `$results` как есть.
5. Иначе все планы диспатчатся внутри `WebClient::group(function() {...}, ['batch' => ..., 'delay' => ...])` (значения `batch`/`delay` берутся из `$opts`, иначе из конфига класса) — на каждый клиент вешается слушатель `response`, который мапит сырой результат (`mapResult()`) и прогоняет его через `processResponse()`, записывая итог в `$results[$i]` **на той же позиции**, что и исходный элемент `$calls`. Если какой-то запрос вообще не получил ответа (например, из-за распространившегося исключения) — соответствующий слот остаётся `null`.

```php
$driver->callMany([
    'ping',
    ['users/{id}', ['id' => 1]],
    ['method' => 'users/{id}', 'params' => ['id' => 2]],
]);
// три запроса уходят конкурентно одним curl_multi-батчем, порядок $results совпадает с порядком $calls
```

`normalizeCallSpec($spec): array` (`private`) — принимает строку (имя метода без параметров), индексированный `[method, params]`, либо ассоциативный `['method' => ..., 'params' => ...]`; для всего остального бросает `\InvalidArgumentException`.

## Ключевые семантические моменты (шпаргалка)

- **Closure-методы** полностью минуют HTTP/валидацию/кеш — чистая локальная логика под общим интерфейсом `call()`/`callMany()`.
- **Route-matching**: `call('users/42/posts')` подходит под зарегистрированный `'users/{id}/posts'`, `id=42` извлекается автоматически; явные значения в `$params` имеют приоритет над извлечёнными из пути.
- **`cache_ttl`**: `0` — кеш выключен; положительное число — TTL в секундах; `-1` — специальный флаг "кешировать бесконечно" (без авто-истечения).
- **События** — единственный предусмотренный способ кастомизации поведения на уровне отдельной стадии без переопределения методов `IntegrationDriver`: `encode_request` (сериализация тела/заголовки), `build_url` (правка итогового URL), `decode_response` (нестандартный формат ответа), `curl_error` (мягкая обработка ошибок вместо исключения), `save_cache` (переопределение TTL перед записью в кеш) и т.д.
- Сообщения всех исключений — на русском языке.
