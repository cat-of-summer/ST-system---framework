<!-- DOCGEN:START -->
# TelegramBot.php
<!-- DOCGEN:END -->

`final class TelegramBot extends ST_system\API\IntegrationDriver` (`ST_system\API\Drivers\Bots`) — драйвер **Telegram Bot API** (`https://api.telegram.org/bot`). Использует трейт [`HasHTMLRules`](../Traits/HasHTMLRules.php.md) для конвертации HTML в разметку, совместимую с Telegram `parse_mode: HTML`, и построен поверх общего HTTP/валидационного/кеш-пайплайна [`IntegrationDriver`](../../IntegrationDriver.php.md).

## Создание инстанса

```php
use ST_system\API\Drivers\Bots\TelegramBot;

$bot = TelegramBot::create('BOT_TOKEN');
```

Токен встраивается в URL при построении запроса (`https://api.telegram.org/bot<TOKEN>/...`); без токена любой вызов бросает исключение. Кеш инстанса включён по умолчанию (`cache.use = true`) — используется для хранения `offset` в `handleUpdate()`.

## Зарегистрированные методы

- `getUpdates` — `offset` (int, опционально);
- `sendMessage` — `chat_id`, `text`, `parse_mode` (`HTML`/`Markdown`/`MarkdownV2`), `reply_markup`;
- `sendPhoto` — `chat_id`, `photo` (url), `caption`, `parse_mode`, `reply_markup`;
- `sendVideo` — `chat_id`, `video` (url), `thumbnail` (url), `caption`, `parse_mode`, `reply_markup`;
- `sendMediaGroup` — `chat_id`, `media` (массив из 2–10 элементов вида `{type: audio|document|photo|video, media: url, thumbnail?, caption?, parse_mode?}`);
- `answerCallbackQuery` — `callback_query_id`, `text`;
- `setWebhook` — `url`, `show_alert`;
- `deleteWebhook`, `getWebhookInfo` — без параметров.

Для `sendMessage`/`sendPhoto`/`sendVideo`/`sendMediaGroup` при `parse_mode === 'HTML'` соответствующее текстовое поле (`text`/`caption`) автоматически прогоняется через `normalizeHtml()` из `HasHTMLRules`.

```php
$bot->call('sendMessage', [
    'chat_id'    => 123456,
    'text'       => '<b>Заказ подтверждён</b>. Спасибо за покупку!',
    'parse_mode' => 'HTML',
]);

$bot->call('sendPhoto', [
    'chat_id' => 123456,
    'photo'   => 'https://example.com/image.jpg',
    'caption' => 'Ваш билет',
]);
```

## Клавиатуры (`reply_markup`)

Передаётся обычным PHP-массивом — драйвер сам валидирует и сериализует его в JSON:

```php
$bot->call('sendMessage', [
    'chat_id'      => 123456,
    'text'         => 'Выберите вариант:',
    'reply_markup' => [
        'inline_keyboard' => [
            [['text' => 'Да', 'callback_data' => 'yes'], ['text' => 'Нет', 'callback_data' => 'no']],
        ],
    ],
]);
```

Каждая кнопка `inline_keyboard` валидируется (`text` обязателен, `url`/`callback_data` — опциональны, наличие `url` вытесняет `callback_data`), кнопка обычной `keyboard` требует только `text`. Если задан `inline_keyboard`, поля `keyboard`/`resize_keyboard`/`one_time_keyboard`/`is_persistent` из результата удаляются (Telegram не допускает их смешивать).

## `handleUpdate(callable $a): void` — long polling

```php
$bot->handleUpdate(function (array $update): bool {
    // обработать $update...
    return true; // true — продолжать (следующий вызов handleUpdate продолжит с этого места), false — прервать текущий проход
});
```

При первом вызове `offset` восстанавливается из кеша инстанса (или `0`, если кеша ещё нет). Метод вызывает `getUpdates` с `offset + 1`, последовательно передаёт каждое полученное обновление в `$a`, останавливаясь, если колбэк вернул `false`. После обработки новый `offset` (id последнего обработанного обновления) сохраняется обратно в кеш — это позволяет вызывать `handleUpdate()` многократно (например, в цикле опроса) без повторной обработки уже виденных обновлений.
