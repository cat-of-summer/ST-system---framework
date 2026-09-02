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
- `drivers.default` — класс драйвера по умолчанию (`InvisibleCaptchaDriver`).
- `drivers.available` — карта имя → класс: `invisible`, `checkbox`, `swipe`, `text`, `smart`,
  `recaptcha`. Последние два живут в `Captcha\Drivers\Service` — это капчи внешних сервисов
  поверх общего `Service\ServiceCaptchaDriver`.

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

Конструктор резолвит драйвер, создаёт его и проверяет `isAvailable()`. **Недоступный драйвер
наружу не отдаётся никогда** — вместо него `\RuntimeException` с именем драйвера, его классом и
пометкой, откуда он взялся: `requested explicitly` (сахар `::text(...)` или `['driver' => 'text']`)
либо `taken from drivers.default`. Класс, не наследующий `CaptchaDriver`, — `\InvalidArgumentException`.

Типичные причины недоступности: у `text` — нет ни GD, ни Imagick, либо в каталоге `fonts` не нашлось
ни одного `ttf`/`otf`; у `smart` и `recaptcha` — не заданы `client_key`/`secret`.

Подмены на `drivers.default` **нет**. Раньше менеджер молча пересоздавал капчу на дефолтном драйвере,
если недоступным оказался явно запрошенный, — и это было худшее из поведений: страница отдавала не тот
виджет, о котором просили, разметка «немного отличалась», а обработчик проверки, знающий свой драйвер,
не мог расшифровать чужой челлендж. Диагностировать такое по симптомам почти невозможно. Ветка к тому же
была наполовину мертва: недоступный **дефолтный** драйвер она не покрывала вовсе (падать было некуда),
и он возвращался сломанным, чтобы выстрелить позже — из `issue()` внутри `putCaptcha()`.

Если нужна именно деградация («нет ключей Yandex — отдай локальную капчу»), она выражается явно на
стороне приложения: проверить условие и выбрать драйвер самому.

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

Два публичных статических метода менеджера не проксируются и не могут быть именами драйверов:
`registerViewEvents()` и `markLive()` — см. ниже.

## Контрибьютор `View`

`CaptchaManager` — четвёртый встроенный контрибьютор [`View`](../View.php.md) (рядом с `Assets`,
`Lang` и `Storage\File`), `View` зовёт его `registerViewEvents()` автоматически из конфига
`contributors`. Подписка ровно одна:

- на `render_open` — `CaptchaDriver::resetEmitted()`, то есть флаги «базовый JS/CSS уже выдан»
  привязываются к рендеру, а не к процессу. Без этого второй запрос в долгоживущем воркере остался
  бы без клиентской части капчи.

Обратное направление — `markLive()`: его зовёт `CaptchaDriver::putCaptcha()`, чтобы объявить свой
вывод некешируемым (`View::live()`). Гард `class_exists(View::class, false)` не даёт капче тянуть за
собой `View` там, где она используется standalone; вне рендера вызов всё равно ничего не делает.

Кеш-события (`cache_open`/`cache_close`/`cache_replay`/`cache_key`) менеджеру не нужны: в кеш `View`
ничего связанного с капчей не попадает по построению, а каталог шрифтов `TextCaptchaDriver` в
зависимости добавляет `Storage\File` — свой контрибьютор на тех же событиях.

Подробности — в [CaptchaDriver](CaptchaDriver.php.md#капча-и-кеш-view).

См. также: `Captcha\CaptchaDriver`, `Captcha\Behavior`, `Cache\CacheManager`, `Access`, `View`.
