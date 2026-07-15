<!-- DOCGEN:START -->
# MaxBot.php
<!-- DOCGEN:END -->

`final class MaxBot extends ST_system\API\IntegrationDriver` (`ST_system\API\Drivers\Bots`) — драйвер бота мессенджера **MAX** (`https://platform-api.max.ru`). Использует трейт [`HasHTMLRules`](../Traits/HasHTMLRules.php.md) для конвертации HTML-разметки в разметку, понятную MAX (`format: html`), и построен поверх общего HTTP/валидационного пайплайна [`IntegrationDriver`](../../IntegrationDriver.php.md) — регистрирует один метод `messages` для отправки сообщений.

## Создание инстанса

```php
use ST_system\API\Drivers\Bots\MaxBot;

$bot = MaxBot::create('BOT_TOKEN');
```

Токен подставляется в заголовок `Authorization` (в сыром виде, без префикса `Bearer`) перед каждым запросом; без него любой вызов метода бросает исключение ("необходим авторизационный токен").

## Метод `messages`

`POST`, JSON-тело. Параметры:

- `user_id` / `chat_id` — положительное целое число (получатель — либо пользователь, либо чат; хотя бы один обязателен);
- `disable_link_preview` — `bool`;
- `format` — `markdown` или `html`;
- `text` — текст сообщения, максимум 4000 символов;
- `notify` — `bool`;
- `attachments`, `link` — произвольные массивы (вложения/ссылка на сообщение);

Валидация в `on_prepare`: должен быть передан `user_id` или `chat_id`; должен быть передан `text` или `attachments`; `text` не длиннее 4000 символов. Если `format === 'html'`, `text` пропускается через `normalizeHtml()` (из `HasHTMLRules`), конвертируясь по правилам `getHtmlRules()`.

`user_id`, `chat_id` и `disable_link_preview` на этапе `build_url` переносятся из тела запроса в query-строку URL (а не в JSON-тело) — так того требует API MAX.

```php
$bot->call('messages', [
    'chat_id' => 123456,
    'text'    => 'Привет!',
]);

// с HTML-разметкой
$bot->call('messages', [
    'user_id' => 42,
    'format'  => 'html',
    'text'    => '<b>Важно:</b> заказ №1 <i>подтверждён</i>.<br>Спасибо!',
]);
```

## HTML-правила (`getHtmlRules()`)

Определяет, как теги HTML превращаются в разметку MAX при `normalizeHtml()`:

- `br` → перевод строки; `p`, `div`, `h3`-`h6`, `dd` → содержимое + `\n`;
- `h1`, `h2` → содержимое оборачивается в `<b>...</b>` и завершается `\n`;
- `li` → `— содержимое\n`; `dt` → `• содержимое: `;
- `form`, `img` — вырезаются целиком (`false`);
- `table`/`tbody`/`thead`/`tfoot`/`tr`/`td`/`th` — сплющиваются в текст (ячейки через пробел, строки — через `\n`, `th` дополнительно оборачивается в `<b>`);
- `b`, `strong`, `i`, `em`, `u`, `ins`, `s`, `del`, `code`, `pre`, `a` — пропускаются как есть (`true`), MAX поддерживает эти теги нативно.

Остальные (не перечисленные) теги разворачиваются рекурсивно — их обёртка отбрасывается, а содержимое обрабатывается дальше.
