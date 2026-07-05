# TelegramBot


## 1. Концепция

Драйвер [Telegram Bot API](https://core.telegram.org/bots/api). Использует трейт `HasHTMLRules` для нормализации HTML в Telegram-форматирование (работает автоматически при `parse_mode=HTML`).

```php
$bot = TelegramBot::create('токен:бота');

// Отправка сообщения
$bot->call('sendMessage', [
    'chat_id'    => 123456789,
    'text'       => '<b>Привет!</b> Как <i>дела</i>?',
    'parse_mode' => 'HTML',
]);

// Фото с кнопками
$bot->call('sendPhoto', [
    'chat_id'      => 123456789,
    'photo'        => 'https://example.com/img.jpg',
    'reply_markup' => [
        'inline_keyboard' => [[['text' => 'Открыть', 'url' => 'https://example.com']]],
    ],
]);

// Обработка обновлений
$bot->handleUpdate(function(array $update): bool {
    $text = $update['message']['text'] ?? '';
    // ...обработка...
    return true; // false — остановить
});
```

## 2. Публичные методы

### `static create(string $token): static`

### `call(string $method, array $params): mixed`

| Метод | Описание |
|---|---|
| `getUpdates` | Получение обновлений. `offset` (опц.). |
| `sendMessage` | Отправка текста. `chat_id`, `text` — обязат. + `parse_mode`, `reply_markup`. |
| `sendPhoto` | Отправка фото. `chat_id`, `photo` — обязат. + `caption`, `parse_mode`, `reply_markup`. |
| `sendVideo` | Отправка видео. `chat_id`, `video` — обязат. + `thumbnail`, `caption`, `parse_mode`, `reply_markup`. |
| `sendMediaGroup` | Альбом (2–10 элементов). `chat_id`, `media` — обязат. |
| `answerCallbackQuery` | Ответ на callback. `callback_query_id` — обязат. |
| `setWebhook` | Установка вебхука. `url` — обязат. |
| `deleteWebhook` | Удаление вебхука. |
| `getWebhookInfo` | Информация о вебхуке. |

### `handleUpdate(callable $callback): void`
Опрашивает `getUpdates` с кэшированным `offset`. Для каждого обновления вызывает `$callback(array $update): bool`. `false` — остановить цикл..php
