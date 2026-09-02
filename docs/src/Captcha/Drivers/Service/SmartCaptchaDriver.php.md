<!-- DOCGEN:START -->
# SmartCaptchaDriver.php
<!-- DOCGEN:END -->

`ST_system\Captcha\Drivers\Service\SmartCaptchaDriver` — Yandex SmartCaptcha под общим контрактом
подсистемы. Виджет и проверка токена живут на стороне Яндекса, но снаружи драйвер выглядит как
любой другой: тот же `putCaptcha()` / `check()` / `includeJs()`, тот же формат полей формы,
та же защита от повтора и тот же счётчик попыток.

Имя драйвера — `smart`. Пришёл на смену `API\Drivers\SmartCaptcha`, который был удалён:
класс не мог одновременно наследовать `IntegrationDriver` и `CaptchaDriver`, поэтому HTTP-вызов
теперь делается напрямую через `HTTP\WebClient`.

Общая с `ReCaptchaDriver` обвязка — разбор и проверка ключей, перепривязка в `spawn()`,
`isAvailable()`, POST-запрос к сервису — вынесена в `Service\ServiceCaptchaDriver`.

## Конфигурация

| Ключ | По умолчанию | Назначение |
|------|--------------|------------|
| `client_key` | `''` | клиентский ключ (обязателен) |
| `secret` | `''` | серверный ключ (обязателен) |
| `endpoint` | `https://smartcaptcha.yandexcloud.net/` | база API |
| `mode` | `'js'` | `js` — монтирование скриптом, `html` — через `data-*` атрибуты |
| `hl` | `'ru'` | язык виджета |
| `invisible` | `false` | невидимый режим |
| `hideShield` | `false` | скрыть значок |
| `test` | `false` | тестовый режим Яндекса |
| `webview` | `false` | режим webview |
| `shieldPosition` | `''` | положение значка |
| `class` / `style` | `''` | дополнительные класс и стиль контейнера |

Ключи проверяются регулярным выражением `/^[a-zA-Z0-9_-]{20,100}$/`; неверный формат — сразу
`\InvalidArgumentException`. Пустые ключи исключением не считаются, но `isAvailable()` вернёт
`false`, и `CaptchaManager` откажется отдать такой драйвер (`\RuntimeException`, подмены на
`drivers.default` нет).

```php
CaptchaManager::setConfig([
    'drivers.smart.client_key' => Config::env('YA_CAPTCHA_KEY'),
    'drivers.smart.secret'     => Config::env('YA_CAPTCHA_SECRET'),
]);

$captcha = CaptchaManager::smart('login_form');
```

## Поведенческий слой

Выключен по умолчанию — `behavior.signals` пуст, `forcedSignals()` ничего не навязывает.
Проверку выполняет Яндекс. При необходимости собственные сигналы включаются явно:

```php
CaptchaManager::smart('login_form', ['behavior' => ['basic', 'env']]);
```

Тогда вердикт будет складываться из обеих проверок: токен принят Яндексом **и**
`score >= min_score`.

## Клиентская часть

`includeJs()` отдаёт `window.STSmartCaptcha` (очередь монтирования, `mount` / `execute` /
`reset` / `getResponse` / `destroy`) и сам подгружает CDN Яндекса, вставляя `<script>` в `head`.
Внутренние события виджета — `smartcaptcha:ready|success|fail|expired|cdn-ready`; подкласс
`STCaptcha.Driver` слушает их и переводит в общие `captcha:*`, записывая токен в поле
`st-captcha-answer`. В невидимом режиме подкласс возвращает `deferSubmit === true`, и базовый
класс откладывает отправку формы до получения токена (таймаут 15 секунд).

В режиме `html` контейнер размечается `data-*` атрибутами, Яндекс монтирует виджет сам и кладёт
токен в поле `smart-token` — `verify()` читает его, если `st-captcha-answer` пуст.

## Проверка на сервере

`verify()` шлёт `POST` на `<endpoint>/validate` через `HTTP\WebClient` с `secret`, `token` и
`ip` (`Access::getClientIp()`), с включённой проверкой TLS. Успех — `status === 'ok'`.

См. также: `Service\ServiceCaptchaDriver`, `Service\ReCaptchaDriver`, `Captcha\CaptchaDriver`,
`Captcha\CaptchaManager`, `HTTP\WebClient`, `Access`.
