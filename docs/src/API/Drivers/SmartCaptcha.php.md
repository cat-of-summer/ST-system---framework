# SmartCaptcha

> Документация сгенерирована AI-агентом на основе исходного кода.

## 1. Концепция

Драйвер [Yandex SmartCaptcha](https://cloud.yandex.ru/services/smartcaptcha). Создаёт экземпляры с алиасом для нескольких виджетов на странице. `secret` хранится внутренне и недоступен извне.

```php
$captcha = SmartCaptcha::create([
    'alias'      => 'main',
    'client_key' => 'ysc1_...',
    'secret'     => 'ysc2_...',
]);

$ok = $captcha->validate($_POST['smart-token']);
```

## 2. Публичные методы

### `static create(array $config): static`

Конфигурация: `alias`, `client_key`, `secret`, `mode` (`js`/`html`), `hl`, `invisible`, `hideShield`, `test`, `webview`, `shieldPosition`, `class`, `style`.

### `validate(string|array $params): bool`
Проверяет токен капчи. Строка обрабатывается как `token`. IP заполняется автоматически через `Access::getClientIp()`.

### `call('validate', array $params): mixed`
Прямой вызов API. Параметры: `token` (обязат.), `ip` (опц.).

### `includeCDN(): string`
HTML-фрагмент для подключения JS-виджета. При повторном вызове — пустая строка.

### `renderWidget(string $containerId = ''): string`
HTML-div виджета капчи.

### `__get(string $name): mixed`
Доступные свойства: `client_key` / `clientKey`, `alias`..php
