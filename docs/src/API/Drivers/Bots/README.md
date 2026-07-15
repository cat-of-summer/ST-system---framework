<!-- DOCGEN:START -->
# Bots

## Файлы

- [MaxBot.php](MaxBot.php.md)
- [TelegramBot.php](TelegramBot.php.md)
- [VkBot.php](VkBot.php.md)

<!-- DOCGEN:END -->

Драйверы интеграции с API мессенджер-ботов: [`MaxBot`](MaxBot.php.md) (MAX-мессенджер), [`TelegramBot`](TelegramBot.php.md) (Telegram Bot API, с long-polling через `handleUpdate()`) и [`VkBot`](VkBot.php.md) (ВКонтакте, с полным OAuth-циклом авторизации и проверкой scope перед вызовом метода). `MaxBot` и `TelegramBot` используют трейт [`HasHTMLRules`](../Traits/HasHTMLRules.php.md) для конвертации HTML-разметки, переданной пользователем, в формат, который понимает конкретный мессенджер.
