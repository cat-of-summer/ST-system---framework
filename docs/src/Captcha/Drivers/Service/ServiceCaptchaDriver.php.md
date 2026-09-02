<!-- DOCGEN:START -->
# ServiceCaptchaDriver.php
<!-- DOCGEN:END -->

`ST_system\Captcha\Drivers\Service\ServiceCaptchaDriver` — общий базис драйверов, которые не
решают челлендж сами, а делегируют его внешнему сервису (Yandex SmartCaptcha, Google reCAPTCHA).
Абстрактный класс между `Captcha\CaptchaDriver` и конкретным драйвером.

Отдельного интерфейса у подсистемы нет и не нужно: контракт задаёт сам `CaptchaDriver`
(`__init` / `isAvailable` / `issue` / `render` / `verify` / `driverJs`), а `CaptchaManager`
проверяет драйвер через `is_subclass_of(..., CaptchaDriver::class)`. `ServiceCaptchaDriver`
добавляет к контракту не новые методы, а готовую реализацию того, что у сервисных капч
совпадает слово в слово.

## Что берёт на себя

| Член | Роль |
|------|------|
| `KEY_REGEX` | `/^[a-zA-Z0-9_-]{20,100}$/` — формат клиентского и серверного ключей |
| `$endpoint`, `$clientKey`, `$secret` | разобранные и проверенные значения конфигурации |
| `$widget` | опции виджета: `class` / `style` плюс всё, что вернул `initWidget()` |
| `serviceConfig(array $extra)` | `baseConfig()` + `endpoint` / `client_key` / `secret` / `class` / `style` + `behavior` с пустым `signals`; `$extra` — ключи конкретного драйвера |
| `__init()` | `final`: разбор трёх ключей, их валидация, сборка `$widget` |
| `__rebind()` | `final`: перепривязка ключей и виджета в `spawn()` с сохранением типов |
| `isAvailable()` | все три значения непусты |
| `forcedSignals()` | пусто — поведенческий слой ничего не навязывает |
| `url(string $path)` | склейка `endpoint` с путём через `Main::glue()` |
| `ask(string $url, array $params)` | `POST` формой через `HTTP\WebClient` с `verify => true`, разбор JSON-ответа в массив |
| `hostClass()`, `hostStyle()` | атрибуты контейнера виджета с экранированием |

## Что реализует наследник

- `getDefaultConfig()` — `static::serviceConfig([...])` со своими ключами;
- `initWidget(array $config): array` — разбор и валидация своих опций, возврат карты виджета;
- `rebindWidget(array $override): void` — необязательный хук: донормализовать виджет после
  `spawn()` (например, снова привести режим в согласие с версией);
- `issue()` / `render()` / `verify()` / `driverJs()` — как у любого драйвера.

Текст исключения о формате ключа строится из `Main::basename(static::class)`, поэтому наследник
получает своё имя в сообщении без дублирования кода: `SmartCaptchaDriver: invalid secret format`.

## Чего здесь нет

Клиентский JS **не обобщается**. У `smartCaptcha` и `grecaptcha` разные наборы методов
(у Google нет `destroy`, у reCAPTCHA v3 нет `render`), а общий каркас у драйверов и так есть —
класс `STCaptcha.Driver` из `CaptchaDriver::bootstrapJs()`. Каждый `driverJs()` остаётся
самодостаточным блоком.

См. также: `Service\SmartCaptchaDriver`, `Service\ReCaptchaDriver`, `Captcha\CaptchaDriver`,
`Captcha\CaptchaManager`, `HTTP\WebClient`.
