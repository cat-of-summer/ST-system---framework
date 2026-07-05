# SmartCaptcha

## 1. Концепция

Драйвер [Yandex SmartCaptcha](https://cloud.yandex.ru/services/smartcaptcha). Состоит из двух частей:

- **PHP-часть** — серверная валидация токена (`validate()`) и генерация HTML/JS для рендера виджета (`putCaptcha()`, `includeCDN()`). Можно создавать несколько экземпляров с разным `alias`, если на странице нужно несколько виджетов с разными ключами/настройками.
- **JS-часть** — при вызове `SmartCaptcha::includeCDN()` на страницу встраивается небольшой фасад `window.STSmartCaptcha`, который оборачивает реальный SDK Yandex (`window.smartCaptcha`) и даёт удобные методы для монтирования виджета, ручного "execute" (как в reCAPTCHA v3) и автоматической привязки к форме.

`secret` хранится только внутри инстанса и никогда не попадает в HTML/JS — наружу уходит только `client_key`.

```php
$captcha = SmartCaptcha::create([
    'alias'      => 'main',
    'client_key' => 'ysc1_...',
    'secret'     => 'ysc2_...',
]);
```

## 2. Жизненный цикл (порядок вызовов)

Это та часть, которая обычно путает — порядок важен:

```
1. SmartCaptcha::create([...])     — создать инстанс под alias (бэк, на каждый запрос свой)
2. SmartCaptcha::includeCDN()      — РОВНО ОДИН РАЗ на страницу, до любого putCaptcha()
3. $captcha->putCaptcha()          — рендер виджета, ВНУТРИ <form>...</form>
4. (submit формы)                  — JS сам положит токен в input[name="smart-token"]
5. $captcha->validate($_POST['smart-token'] ?? '')  — на бэке, в обработчике формы
```

Нарушения, которые ловит сам код:

- `putCaptcha()` до `SmartCaptcha::includeCDN()` → `LogicException('SmartCaptcha::includeCDN() must be called before putCaptcha()')`.
- `create()` дважды с одним `alias` **в рамках одного запроса/процесса** → `LogicException("SmartCaptcha alias '...' already taken")`. Если рендер и валидация формы происходят в одном и том же запросе (например, общий AJAX-эндпоинт), оборачивайте `create()` в свой хелпер (см. [UC7](#uc7-валидация-на-бэке--защита-от-alias-already-taken)).
- `SmartCaptcha::includeCDN()` идемпотентен — второй и последующие вызовы возвращают `''`. Это нормально (например, если хук стоит и в `wp_head`, и где-то ещё), но не рассчитывайте, что он каждый раз что-то выводит.

## 3. Справочник PHP-методов

| Метод | Тип | Сигнатура | Описание |
|---|---|---|---|
| `create` | static | `create(array $config): static` | Конфиг: `alias`, `client_key`, `secret` (обязательны кроме alias), `mode` (`js`\|`html`), `hl`, `invisible`, `hideShield`, `test`, `webview`, `shieldPosition`, `class`, `style`. |
| `includeCDN` | **static** | `includeCDN(): string` | Один раз на странице. Возвращает бутстрап `window.STSmartCaptcha` + `<script src="https://smartcaptcha.yandexcloud.net/captcha.js?...">` + `registerInstance()` для всех созданных к этому моменту инстансов. Повторный вызов → `''`. |
| `includeCDN` | instance | `$captcha->includeCDN(): string` | Если бутстрап ещё не выводился на странице — делегирует в статический `includeCDN()` и возвращает его целиком (бутстрап + регистрации всех созданных инстансов). Если бутстрап уже выведен — эмитит только `registerInstance({alias, sitekey, hl})` для этого инстанса. То есть `$captcha->includeCDN()` и `SmartCaptcha::includeCDN()` равнозначны, если это первый вызов на странице; вызывайте любой из них один раз. |
| `putCaptcha` | instance | `putCaptcha(array $params = []): string` | HTML `<div class="smart-captcha">` (+ скрипт `mount`+`bindForm`, если `mode: 'js'`, либо чистый `data-*` div, если `mode: 'html'`). **Должен быть выведен внутри `<form>`**, чтобы автопривязка submit сработала. |
| `validate` | instance | `validate(string\|array $params): bool` | Строка трактуется как `token`. `ip` подставляется автоматически (`Access::getClientIp()`), можно передать явно через массив. `true` только если ответ Яндекса содержит `status: 'ok'`. |
| `call` | instance | `call('validate', array $params): mixed` | Низкоуровневый прямой вызов API (то же, что вызывает `validate()` внутри). |
| `__get` | instance | `$captcha->client_key` / `$captcha->clientKey` / `$captcha->alias` | Доступ к публичным свойствам. `secret` недоступен. |

## 4. Справочник JS-методов (`window.STSmartCaptcha`)

Доступен после вывода `SmartCaptcha::includeCDN()`. Все методы безопасно вызывать до полной загрузки SDK Яндекса — `mount`/`execute` сами поставят вызов в очередь.

| Метод | Сигнатура | Описание |
|---|---|---|
| `ready` | `boolean` (getter) | `true`, когда SDK Яндекса загрузился и готов рендерить. |
| `registerInstance` | `registerInstance(cfg)` | Регистрирует `{alias, sitekey, hl}` — вызывается автоматически из PHP, руками не нужен. |
| `mount` | `mount(id, options)` | Рендерит виджет в `<div id="...">`. Если SDK не готов — ставит в очередь до `ready`. |
| `execute` | `execute(id)` | Запускает невидимую проверку для уже смонтированного виджета. |
| `reset` | `reset(id)` | Сбрасывает состояние/токен виджета. |
| `getResponse` | `getResponse(id)` | Текущий токен виджета (или `null`). |
| `destroy` | `destroy(id)` | Удаляет виджет. |
| `executeAndGetToken` | `executeAndGetToken(id, cb)` | Аналог `grecaptcha.execute(...).then(token => ...)`: один `execute()` + получение результата в `cb(token)` (`token === ''` при ошибке/просрочке). Сам делает `reset()` после. |
| `getToken` | `getToken(cb, alias?)` | Самый высокий уровень — полный аналог `grecaptcha.execute(siteKey, {...}).then(token => ...)`: при первом вызове сам создаёт скрытый `<div>` вне форм и монтирует в него invisible-виджет для указанного (или первого зарегистрированного) `alias`, кэширует id виджета и дальше просто переиспользует его через `executeAndGetToken`. Не требует ни `putCaptcha()`, ни ручного управления id — только `includeCDN()` на странице. |
| `bindForm` | `bindForm(id, form?)` | Навешивает перехват `submit` (capture phase) на форму: первый submit — `preventDefault` → `executeAndGetToken` → токен в `input[name="smart-token"]` → повторный `form.requestSubmit()`/`submit()`. Если `form` не передана — берёт `closest('form')` от контейнера `id` (если форма не найдена — тихо ничего не делает). |
| `mountAndBind` | `mountAndBind(form, opts?)` | Создаёт скрытый `.smart-captcha` div внутри `form` (если такого ещё нет) + `mount()` (invisible, `hideShield: true` по умолчанию) + `bindForm()`. Готовый способ "повесить капчу на форму одной строкой JS, без правки PHP-шаблона". `opts.alias` — какой зарегистрированный alias использовать (по умолчанию — первый зарегистрированный). |

## 5. Кейсы использования

### UC1 — Одна форма, видимый виджет

Классический сценарий: форма регистрации с чекбоксом-капчей.

```php
// один раз в <head> / common-layout
echo SmartCaptcha::includeCDN();

// при инициализации (config/bootstrap)
$captcha = SmartCaptcha::create([
    'alias'      => 'register',
    'client_key' => env('SMARTCAPTCHA_KEY'),
    'secret'     => env('SMARTCAPTCHA_SECRET'),
]);
```

```php
<form method="post" action="/register">
    <input type="text" name="email">
    <input type="password" name="password">

    <?= $captcha->putCaptcha() ?>

    <button type="submit">Зарегистрироваться</button>
</form>
```

Обработчик:

```php
if (!$captcha->validate($_POST['smart-token'] ?? '')) {
    // показать ошибку "подтвердите, что вы не робот"
}
```

JS ничего писать не нужно — `putCaptcha()` сам встраивает `mount()+bindForm()`, виджет внутри формы, submit перехватывается автоматически.

### UC2 — Одна форма, invisible-виджет с авто-привязкой

Форма логина — не нужен видимый чекбокс, проверка должна быть прозрачной для пользователя.

```php
$captcha = SmartCaptcha::create([
    'alias'      => 'login',
    'client_key' => env('SMARTCAPTCHA_KEY'),
    'secret'     => env('SMARTCAPTCHA_SECRET'),
    'invisible'  => true,
    'hideShield' => true, // обязательное условие лицензии Yandex при invisible — см. их доку
]);
```

```php
<form method="post" action="/login">
    <input type="text" name="login">
    <input type="password" name="password">

    <?= $captcha->putCaptcha() ?>

    <button type="submit">Войти</button>
</form>
```

Поведение идентично UC1, только виджет не отображается. `bindForm()` сработал автоматически, потому что `putCaptcha()` вызван **внутри** `<form>`.

### UC3 — `mode: 'html'` (декларативный виджет)

Подходит для статических/тяжело кэшируемых страниц, где не хочется генерировать PHP-скрипт инициализации при каждом рендере — Yandex `captcha.js` сам сканирует `data-*` атрибуты при `render=onload`.

```php
$captcha = SmartCaptcha::create([
    'alias'      => 'static_page',
    'client_key' => env('SMARTCAPTCHA_KEY'),
    'secret'     => env('SMARTCAPTCHA_SECRET'),
    'mode'       => 'html',
]);
```

```php
<?= SmartCaptcha::includeCDN() // всё ещё нужен — он подключает сам captcha.js ?>
...
<form method="post" action="/feedback">
    <?= $captcha->putCaptcha() // вернёт чистый <div data-sitekey="..." data-hl="ru" ...> без <script> ?>
    <button type="submit">Отправить</button>
</form>
```

В этом режиме `mountAndBind`/`bindForm` через нашу обёртку **не задействуются** (Yandex сам управляет виджетом) — если нужна авто-проверка перед submit, используйте `mode: 'js'` (UC1/UC2) или сами слушайте submit формы и читайте `smartCaptcha.getResponse(...)` от родного SDK.

### UC4 — Несколько алиасов на одной странице

Например: на одной странице есть форма обратной связи с тестовым ключом (для отдела поддержки на стейджинге) и форма заказа с прод-ключом.

```php
$feedback = SmartCaptcha::create([
    'alias'      => 'feedback',
    'client_key' => env('SMARTCAPTCHA_KEY_TEST'),
    'secret'     => env('SMARTCAPTCHA_SECRET_TEST'),
    'test'       => true,
]);

$order = SmartCaptcha::create([
    'alias'      => 'order',
    'client_key' => env('SMARTCAPTCHA_KEY_PROD'),
    'secret'     => env('SMARTCAPTCHA_SECRET_PROD'),
]);

echo SmartCaptcha::includeCDN(); // зарегистрирует оба alias одним бутстрапом
```

Каждая форма рендерит `putCaptcha()` своего инстанса — виджеты независимы, `validate()` тоже вызывается на соответствующем инстансе (по тому, какая форма прислала POST).

### UC5 — Защита ВСЕХ форм на сайте без правки шаблонов

Главный запрошенный кейс: нужно, чтобы капча стояла перед submit-ом **любой** формы на сайте, не вставляя `putCaptcha()` в каждый шаблон формы вручную (плагины, виджеты, формы из чужого кода).

**PHP — один раз глобально (например, в WordPress):**

```php
add_action('wp_head', static function () {
    echo SmartCaptcha::includeCDN();
});
```

`SmartCaptcha::create([...])` для нужного `alias` должен быть выполнен где-то раньше этого хука (например, в `init` или в общем конфиге плюгина), чтобы `includeCDN()` успел его зарегистрировать.

**JS — общий bootstrap-скрипт, подключается один раз на сайте (без правок форм):**

```javascript
(function () {
    function protect(form) {
        if (form.__stCaptchaBound || form.matches('[data-no-captcha]')) return;
        window.STSmartCaptcha.mountAndBind(form, { alias: 'main' });
    }

    function scan(root) {
        root.querySelectorAll('form:not([data-no-captcha])').forEach(protect);
    }

    document.addEventListener('DOMContentLoaded', function () {
        scan(document);

        // формы, добавленные позже динамически (модалки, AJAX-виджеты, popup-формы)
        new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes.forEach(function (node) {
                    if (node.nodeType !== 1) return;
                    if (node.matches && node.matches('form')) protect(node);
                    else if (node.querySelectorAll) scan(node);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    });
})();
```

Здесь не нужно ждать `STSmartCaptcha.ready` — `mountAndBind` → `mount()` сам поставит рендер в очередь, если SDK ещё не загрузился.

**Исключить конкретную форму** (например, форму поиска) — просто пометить атрибутом:

```html
<form action="/search" data-no-captcha>...</form>
```

**Бэк — одна общая точка валидации**, куда стекаются все обработчики форм:

```php
function st_smartcaptcha_check(): bool {
    $captcha = SmartCaptcha::create([ /* ...alias 'main', ключи... */ ]);
    return $captcha->validate($_POST['smart-token'] ?? '');
}
```

(с учётом UC7 — если этот хелпер может вызываться больше одного раза за запрос, нужен `static`-кэш инстанса).

### UC6 — Точный аналог текущего recaptcha-паттерна

Если в проекте уже есть общий перехватчик отправки форм в стиле:

```javascript
if (window.recaptcha_site_key && typeof grecaptcha !== 'undefined') {
    grecaptcha.ready(() => {
        grecaptcha.execute(window.recaptcha_site_key, { action: 'submit' }).then(token => {
            let input = form.querySelector('input[name="g-recaptcha-response"]');
            if (!input) {
                input = document.createElement('input');
                input.type  = 'hidden';
                input.name  = 'g-recaptcha-response';
                form.appendChild(input);
            }
            input.value = token;
            doSubmit();
        });
    });
} else {
    doSubmit();
}
```

— и хочется не переписывать архитектуру, а просто подставить SmartCaptcha на то же место, используется встроенный `window.STSmartCaptcha.getToken(cb, alias?)` — он сам лениво создаёт один общий скрытый invisible-виджет (по одному на каждый `alias`, кэшируется в замыкании бутстрапа) и сам делает `execute` + отдаёт токен в `cb`. Никакого `putCaptcha()` вне формы и никаких глобальных JS-переменных под id заводить не нужно — `getToken()` уже знает sitekey/hl из `registerInstance(...)`, который выводит `SmartCaptcha::includeCDN()` (то, что у вас уже стоит в `wp_head`).

**PHP — без изменений**, достаточно того, что уже есть:
```php
add_action('wp_head', static function () {
    echo SmartCaptcha::includeCDN();
});
```

**JS — на месте recaptcha-блока:**

```javascript
if (window.STSmartCaptcha) {
    window.STSmartCaptcha.getToken(function (token) {
        let input = form.querySelector('input[name="smart-token"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'smart-token';
            form.appendChild(input);
        }
        input.value = token;
        doSubmit();
    });
} else {
    doSubmit();
}
```

Структура 1:1 повторяет имеющийся recaptcha-код — отличия только в именах (`getToken` вместо `grecaptcha.execute(...).then(...)`, поле `smart-token` вместо `g-recaptcha-response`). `STSmartCaptcha.ready` дополнительно проверять не нужно — `getToken()` внутри использует `mount()`/`executeAndGetToken()`, которые сами разберутся с очередью, если SDK Яндекса ещё не успел загрузиться.

Если на странице зарегистрировано несколько `alias` (см. UC4) и нужен токен конкретного из них — передайте его вторым аргументом: `getToken(cb, 'order')`. Без второго аргумента берётся первый зарегистрированный alias (этого достаточно, если на странице обычно один SmartCaptcha-инстанс — как в UC5/UC6).

### UC7 — Валидация на бэке + защита от "alias already taken"

Если рендер виджета и валидация токена происходят в рамках одного и того же PHP-запроса (например, единый AJAX-эндпоинт сначала отдаёт форму, потом её же проверяет), повторный `SmartCaptcha::create()` с тем же `alias` бросит исключение. Решение — простой хелпер с локальным кэшем инстанса:

```php
function smart_captcha(): SmartCaptcha {
    static $instance = null;

    return $instance ??= SmartCaptcha::create([
        'alias'      => 'main',
        'client_key' => env('SMARTCAPTCHA_KEY'),
        'secret'     => env('SMARTCAPTCHA_SECRET'),
    ]);
}
```

```php
// рендер
echo smart_captcha()->putCaptcha();

// валидация (тот же запрос или другой — без разницы)
if (!smart_captcha()->validate($_POST['smart-token'] ?? '')) {
    http_response_code(422);
    exit('captcha failed');
}
```

## 6. Подводные камни / FAQ

- **`putCaptcha()` до `includeCDN()`** → `LogicException`. Всегда выводите `SmartCaptcha::includeCDN()` раньше любого `putCaptcha()` по порядку рендера страницы (head/начало body), не после.
- **HTML от `putCaptcha()` обязан быть внутри `<form>...</form>`**, если нужна авто-привязка submit. Снаружи формы — это легальный приём (см. UC6), но тогда проверка submit полностью на вас.
- **`SmartCaptcha::includeCDN()` идемпотентен** — второй вызов вернёт `''`. Не оборачивайте логику вокруг "а вдруг он не вызвался" — лучше гарантировать единственную точку вызова (один хук/один layout-файл).
- **Invisible ≠ "без DOM-узла"** — даже у скрытого виджета должен быть реальный `<div id="...">` в DOM (можно `display:none`), потому что Yandex SDK рендерит виджет именно в него.
- **`test: true` / `hideShield: true`** — оставляйте только на стейджинге/тесте, не в проде (нарушение условий использования Yandex SmartCaptcha и подмена реальной проверки).
- **Порядок слушателей `submit`**, если на форме уже есть свой JS-обработчик (своя AJAX-отправка): `bindForm`/`mountAndBind` навешивают перехватчик в capture-фазе и при первом submit гасят событие (`stopImmediatePropagation`) до получения токена, а затем сами вызывают `form.requestSubmit()` — это запускает submit заново, и при повторном проходе перехватчик себя не блокирует (флаг `__stCaptchaPassed`), так что остальные слушатели формы получат событие штатно. Если вместо `bindForm`/`mountAndBind` пишете свой обработчик (как в UC6) — порядок проверяйте сами.
