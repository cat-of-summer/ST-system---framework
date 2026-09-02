<!-- DOCGEN:START -->
# ReCaptchaDriver.php
<!-- DOCGEN:END -->

`ST_system\Captcha\Drivers\Service\ReCaptchaDriver` — Google reCAPTCHA под общим контрактом
подсистемы. Виджет и проверка токена живут на стороне Google, но снаружи драйвер выглядит как
любой другой: тот же `putCaptcha()` / `check()` / `includeJs()`, тот же формат полей формы,
та же защита от повтора и тот же счётчик попыток.

Имя драйвера — `recaptcha`. Общая с `SmartCaptchaDriver` обвязка (разбор ключей, `spawn()`,
`isAvailable()`, HTTP-вызов) вынесена в `Service\ServiceCaptchaDriver`.

Поддержаны обе живые версии: **v2** — чекбокс «Я не робот» и невидимый режим, **v3** — без
виджета, с баллом доверия от Google.

## Конфигурация

| Ключ | По умолчанию | Назначение |
|------|--------------|------------|
| `client_key` | `''` | site key (обязателен) |
| `secret` | `''` | secret key (обязателен) |
| `endpoint` | `https://www.google.com/recaptcha/` | база API и CDN |
| `version` | `'v2'` | `v2` или `v3` |
| `mode` | `'js'` | `js` — явный рендер скриптом, `html` — авторендер Google по `data-*` |
| `hl` | `'ru'` | язык виджета; у Google уходит в query-строку `api.js`, а не в опции рендера |
| `invisible` | `false` | невидимый режим v2 (`size: 'invisible'`) |
| `theme` | `'light'` | `light` / `dark` |
| `size` | `''` | `normal` / `compact`; игнорируется при `invisible` |
| `badge` | `''` | `bottomright` / `bottomleft` / `inline` — положение значка |
| `action` | `'submit'` | имя действия v3; сверяется с ответом `siteverify` |
| `class` / `style` | `''` | дополнительные класс и стиль контейнера |

Ключи проверяются регулярным выражением `/^[a-zA-Z0-9_-]{20,100}$/`; неверный формат — сразу
`\InvalidArgumentException`. Значения `version`, `mode`, `theme`, `size`, `badge` вне списка —
тоже исключение. Пустые ключи исключением не считаются, но `isAvailable()` вернёт `false`,
и `CaptchaManager` откажется отдать такой драйвер (`\RuntimeException`, подмены на
`drivers.default` нет).

Взаимоисключающие комбинации не запрещаются, а нормализуются: `version => 'v3'` включает
`invisible`, а `invisible` переводит `mode` в `js` — у v3 нет авторендера, а невидимая v2 в
режиме `html` требовала бы собственного `data-callback`.

```php
CaptchaManager::setConfig([
    'drivers.recaptcha.client_key' => Config::env('RECAPTCHA_KEY'),
    'drivers.recaptcha.secret'     => Config::env('RECAPTCHA_SECRET'),
]);

$captcha = CaptchaManager::recaptcha('login_form');
$v3      = CaptchaManager::recaptcha('checkout', ['version' => 'v3', 'action' => 'checkout']);
```

Для регионов, где `google.com` недоступен, Google держит зеркало — достаточно сменить базу,
она задаёт и адрес проверки, и адрес CDN:

```php
'drivers.recaptcha.endpoint' => 'https://www.recaptcha.net/recaptcha/',
```

Тестовые ключи Google (`6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI` /
`6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe`) задаются как обычные: виджет всегда проходит
проверку, годится для локальной отладки формы.

## Порог доверия v3

Балл, который вернул Google, сравнивается с базовым `min_score` (`0.5` по умолчанию — ровно то
значение, которое рекомендует Google). Отдельного ключа нет:

```php
CaptchaManager::recaptcha('checkout', ['version' => 'v3', 'min_score' => 0.7]);
```

Тот же `min_score` служит порогом поведенческого слоя, но по умолчанию тот выключен
(`behavior.signals` пуст), так что шкала одна. Если включить собственные сигналы, порог станет
общим для двух разных шкал — задавать его тогда нужно с оглядкой на обе.

После `check()` доступны два атрибута с сырым ответом Google:

- `remote_score` — балл v3 (`null`, если сервис его не прислал);
- `remote_errors` — содержимое `error-codes`, полезно при разборе отказов.

Они не пересекаются с `score` / `report` / `reasons`, которые заполняет поведенческий слой.

## Поведенческий слой

Выключен по умолчанию — `behavior.signals` пуст, `forcedSignals()` ничего не навязывает.
Проверку выполняет Google. При необходимости собственные сигналы включаются явно:

```php
CaptchaManager::recaptcha('login_form', ['behavior' => ['basic', 'env']]);
```

Тогда вердикт складывается из обеих проверок: токен принят Google **и** `score >= min_score`.

## Клиентская часть

`includeJs()` отдаёт `window.STReCaptcha` (очередь монтирования, `load` / `mount` / `execute` /
`reset` / `getResponse` / `token`). Адрес CDN приезжает в конфигурации конкретного виджета
(`cfg.cdn`), а не зашит в скрипт: блок `driverJs()` эмитится один раз на драйвер, а `hl` и
режим рендера — часть URL, и их нельзя фиксировать по первому инстансу. Собирается адрес так:

| Случай | URL |
|--------|-----|
| v2, `mode: js` | `<endpoint>/api.js?render=explicit&hl=…` |
| v2, `mode: html` | `<endpoint>/api.js?hl=…` (авторендер) |
| v3 | `<endpoint>/api.js?render=<sitekey>&hl=…` |

Готовность ловится через `script.onload` плюс `grecaptcha.ready()` — параметр `onload=` в
query-строке документирован только для `render=explicit` и для v3 не годится. Если скрипт
Google уже есть на странице, драйвер это распознаёт и не вставляет второй.

Внутренние события виджета — `recaptcha:ready|success|fail|expired|cdn-ready`; подкласс
`STCaptcha.Driver` слушает их и переводит в общие `captcha:*`, записывая токен в поле
`st-captcha-answer`. Коллбэки Google называются `callback` / `expired-callback` /
`error-callback`.

`deferSubmit === true` для v3 и для невидимой v2 — базовый класс откладывает отправку формы до
получения токена (таймаут 15 секунд). Для v3 токен берётся именно в момент сабмита
(`grecaptcha.execute(sitekey, {action})`): он живёт две минуты, брать его при загрузке страницы
бессмысленно.

В режиме `html` контейнер размечается классом `g-recaptcha` и `data-*` атрибутами, Google
монтирует виджет сам и кладёт токен в поле `g-recaptcha-response` — `verify()` читает его,
если `st-captcha-answer` пуст. Класс `st-recaptcha` на контейнере есть в обоих режимах: это
селектор, по которому драйвер находит хост.

## Проверка на сервере

`verify()` шлёт `POST` на `<endpoint>/api/siteverify` через `HTTP\WebClient` с `secret`,
`response` и `remoteip` (`Access::getClientIp()`), с включённой проверкой TLS. Успех —
`success === true`. Для v3 дополнительно сверяется `action` (значение записано в секретную
часть состояния при выпуске, поэтому переживает `spawn()`) и `score >= min_score`.

См. также: `Service\ServiceCaptchaDriver`, `Service\SmartCaptchaDriver`, `Captcha\CaptchaDriver`,
`Captcha\CaptchaManager`, `HTTP\WebClient`, `Access`.
