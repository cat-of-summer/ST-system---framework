<!-- DOCGEN:START -->
# CaptchaManager.php
<!-- DOCGEN:END -->

`ST_system\Captcha\CaptchaManager` — единый вход в подсистему капчи. Это `final class`, фасад и
фабрика над драйверами (`ST_system\Captcha\CaptchaDriver` и его наследниками в
`Captcha\Drivers\*`): резолвит драйвер по имени, конфигурирует его и делегирует ему всю работу —
выдачу челленджа, HTML, JS и проверку. Архитектурно повторяет `Cache\CacheManager`.

## Конфигурация

`getDefaultConfig()` задаёт:

- `default.ttl` — время жизни челленджа, `300` секунд.
- `default.attempts` — сколько раз можно ошибиться по одному `id` до аннулирования, `3`.
- `default.min_score` — порог доверия по поведенческим факторам, `0.5` (см. `Captcha\Behavior`).
- `default.field_prefix` — префикс имён полей формы, `'st-captcha'`.
- `default.salt` — соль для шифрования состояния. Пустая — используется `Access::config('salt')`;
  если и она пуста, состояние ложится в кеш открытым JSON.
- `default.cache` — где хранится состояние: `driver` (`'filesystem'`) и `dir`
  (`<CacheManager default.dir>/Captcha`).
- `default.behavior` — набор поведенческих сигналов, веса групп и параметры PoW.
- `drivers.default` — класс драйвера по умолчанию (`CheckboxCaptchaDriver`).
- `drivers.available` — карта имя → класс: `invisible`, `checkbox`, `swipe`, `text`, `smart`.

`CaptchaManager::setConfig([...])` одним вызовом настраивает и сам менеджер, и конкретные
драйверы — ключи `drivers.<имя>.<параметр>` (кроме служебных `drivers.default` и
`drivers.available`) автоматически уводятся в `<КлассДрайвера>::setConfig([...])`:

```php
CaptchaManager::setConfig([
    'default.ttl'              => 120,
    'default.salt'             => Config::env('CAPTCHA_SALT'),
    'drivers.default'          => 'swipe',
    'drivers.text.length'      => 5,
    'drivers.text.width'       => 320,
    'drivers.smart.client_key' => Config::env('YA_CAPTCHA_KEY'),
    'drivers.smart.secret'     => Config::env('YA_CAPTCHA_SECRET'),
]);
```

Настраивать нужно **до** первого создания инстанса: конфиг классов инициализируется лениво и
затем неизменяем (`Config::fillImmutableConfig`).

## Создание и автофолбэк драйвера

```php
$captcha = CaptchaManager::make('login_form', [
    'driver'    => 'text',   // необязательно; по умолчанию drivers.default
    'ttl'       => 120,
    'min_score' => 0.5,
    'behavior'  => true,
    // + специфичные для драйвера ключи (length, char_pool, target, client_key, ...)
]);
```

`$key` — **обязателен**. Это произвольное значение (хэшируется через `Main::hash()`), которое
привязывает челлендж к конкретной форме: токен, выданный для `'contacts'`, не пройдёт проверку
в обработчике `'login_form'` (`error === 'mismatch'`). Пустое значение (`''`, `null`, `[]`,
`false`) — `\InvalidArgumentException`.

Для каждого имени из `drivers.available` менеджер отдаёт одноимённый статический метод-сахар:

```php
$captcha = CaptchaManager::text('login_form', ['ttl' => 120]);
$captcha = CaptchaManager::swipe('contacts');
```

Конструктор резолвит драйвер, создаёт его и проверяет `isAvailable()`. **Если запрошенный
драйвер недоступен** (нет ни GD, ни Imagick для `text`; не заданы ключи Yandex для `smart`)
**и это не драйвер по умолчанию** — менеджер молча пересоздаёт капчу на `drivers.default`.
Если класс не является наследником `CaptchaDriver`, бросается `\InvalidArgumentException`.

## Проверка

Проверка выполняется **только через инстанс** — статического шортката нет намеренно. Обработчик
обязан явно указать и ключ формы, и драйвер: тогда чужой или подменённый челлендж не пройдёт, а
из кода видно, что именно проверяется.

```php
$captcha = CaptchaManager::text('login_form');

if (!$captcha->check(Request::post()))
    Response::json([
        'error'   => $captcha->error,     // expired | replayed | mismatch | attempts
                                          // | honeypot | answer | behavior
        'score'   => $captcha->score,     // 0.0 .. 1.0
        'reasons' => $captcha->reasons,   // ['webdriver', 'linear_pointer', ...]
    ], 422)->send();
```

Драйвер при проверке должен совпадать с тем, что выдавал челлендж: `check()` сверяет хэш ключа
формы, а расшифровать `secret` и понять ответ умеет только сам драйвер. Если драйвер выбирается
конфигом (`drivers.default`), обе стороны получают его автоматически.

## Проксирование

Всё, что не перехвачено менеджером, уходит в драйвер:

- `__call` — `putCaptcha()`, `check()`, `includeJs()`, `includeCss()`, `refresh()` и прочее.
- `__call('make', $key, $override)` — клонирует живой драйвер через `CaptchaDriver::spawn()` и
  возвращает новый `CaptchaManager` (переиспользование настроек без повторного резолва).
- `__get` / `__isset` — атрибуты драйвера (`score`, `reasons`, `report`, `passed`, `error`,
  `issued_id`, `ttl`, `min_score`, `behavior`, `raw_key`), плюс `driver` — сам объект драйвера.

Неизвестное статическое имя, не совпадающее ни с одним драйвером, бросает
`\BadMethodCallException`.

См. также: `Captcha\CaptchaDriver`, `Captcha\Behavior`, `Cache\CacheManager`, `Access`.
