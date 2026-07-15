<!-- DOCGEN:START -->
# SmartCaptcha.php
<!-- DOCGEN:END -->

`final class SmartCaptcha extends IntegrationDriver` (`ST_system\API\Drivers\SmartCaptcha`) — драйвер для [Яндекс SmartCaptcha](https://cloud.yandex.ru/docs/smartcaptcha/) (`https://smartcaptcha.yandexcloud.net/`). В отличие от большинства других драйверов, у SmartCaptcha два разных назначения одновременно:

1. Серверная **верификация токена** (`validate`) — обычный вызов стороннего REST API через [`IntegrationDriver`](../IntegrationDriver.php.md).
2. **Клиентский виджет** — генерация JS-бутстрапа, подключение CDN Яндекса и рендер HTML-разметки виджета капчи на странице (весь этот функционал не имеет отношения к `IntegrationDriver::call()`, это самостоятельная логика самого класса).

## Создание инстанса

```php
$captcha = SmartCaptcha::create([
    'alias'      => 'loginForm',   // необязательный уникальный алиас инстанса (по умолчанию 'smartCaptcha')
    'client_key' => 'ck_...',      // обязателен, публичный (клиентский) ключ, 20-100 символов [a-zA-Z0-9_-]
    'secret'     => 'sk_...',      // обязателен, серверный ключ, тот же формат
    'mode'       => 'js',          // 'js' | 'html' — способ рендера putCaptcha()
    'hl'         => 'ru',          // язык виджета
    'invisible'      => false,
    'hideShield'     => false,
    'test'           => false,
    'webview'        => false,
    'shieldPosition' => '',
    'class'          => '',        // дополнительный CSS-класс контейнера
    'style'          => '',        // inline-style контейнера
]);
```

Повторное создание инстанса с уже занятым `alias` бросает `\LogicException`. `client_key`/`secret` валидируются регулярным выражением `KEY_REGEX = '/^[a-zA-Z0-9_-]{20,100}$/'`. Каждый созданный инстанс регистрируется в статическом реестре `self::$instances[$alias]` — это нужно клиентскому JS-бутстрапу, чтобы находить конфигурацию нужного виджета по алиасу.

## Метод `validate` — серверная проверка токена

```php
$captcha->call('validate', ['token' => $_POST['smart-token'], 'ip' => $clientIp]);
// либо через удобную обёртку:
$ok = $captcha->validate($_POST['smart-token']); // bool
```

Параметры: `token` (обязателен, строка, `trim`), `ip` (строка; если не передан — по умолчанию `Access::getClientIp()`). На событии `call` в параметры автоматически подмешивается `secret` (серверный ключ инстанса) — вызывающему коду передавать его вручную не нужно.

`public function validate($params): bool` — удобная обёртка над `call('validate', ...)`: если передана не строка/массив, а скалярное значение, оно оборачивается в `['token' => (string)$params]`; возвращает `true` только если ответ — массив и `$response['status'] === 'ok'`.

## Клиентский виджет: `includeCDN()`, `putCaptcha()`

- `SmartCaptcha::includeCDN()` (статический вызов) или `$captcha->includeCDN()` (вызов на инстансе) — оба маршрутизируются через магические методы `__callStatic`/`__call` (единственный поддерживаемый в них метод — `includeCDN`; любое другое имя бросает `\BadMethodCallException`). Возвращает HTML/JS для вставки в страницу:
  - **Статический** `SmartCaptcha::includeCDN()` всегда пытается вывести полный JS-бутстрап (`window.STSmartCaptcha`, подключение `captcha.js` с Яндекса) плюс JS-регистрацию всех уже созданных на момент вызова инстансов; если бутстрап уже был выведен ранее (флаг `self::$cdnIncluded`), возвращает пустую строку.
  - **Инстансный** `$captcha->includeCDN()` — если бутстрап ещё не выводился, делает то же самое (полный бутстрап + регистрация всех инстансов); если бутстрап **уже** выведен (кем угодно — другим инстансом или статическим вызовом), возвращает только собственную JS-регистрацию этого инстанса (`window.STSmartCaptcha.registerInstance(...)`), либо пустую строку, если этот инстанс уже был зарегистрирован ранее (флаг `jsRegistered`).
  ```php
  echo SmartCaptcha::includeCDN(); // один раз в <head> или перед </body>
  ```

- `public function putCaptcha(array $params = []): string` — рендерит HTML одного виджета капчи (уникальный `id`, атрибут `data-captcha-alias`). Требует, чтобы `includeCDN()` уже был вызван (`self::$cdnIncluded === true`), иначе бросает `\LogicException`. Переданные `$params` переопределяют конфиг инстанса (`mode`, `hl`, `invisible`, `hideShield`, `test`, `webview`, `shieldPosition`, `class`, `style`) только для этого конкретного вызова.
  - В режиме `mode = 'html'` — статичная разметка `<div>` с `data-*`-атрибутами, которую сам виджет Яндекса подхватывает по `data-sitekey`.
  - В режиме `mode = 'js'` (по умолчанию) — пустой `<div>` плюс инлайновый `<script>`, монтирующий и биндящий виджет к ближайшей форме через `window.STSmartCaptcha.mount()`/`bindForm()`.
  ```php
  echo $captcha->putCaptcha(['invisible' => true]);
  ```

## `__get()` — служебные свойства

`public function __get(string $name)` — доступны `client_key` (он же `clientKey`) и `alias`. Обращение к любому другому имени бросает `\LogicException`.
