<!-- DOCGEN:START -->
# Traits

## Файлы

- [HasHTMLRules.php](HasHTMLRules.php.md)

<!-- DOCGEN:END -->

Переиспользуемые трейты для драйверов интеграций. [`HasHTMLRules`](HasHTMLRules.php.md) даёт общий механизм конвертации HTML в разметку конкретного мессенджера: парсит HTML в DOM и рекурсивно применяет декларативную карту правил (`getHtmlRules()`), которую должен реализовать каждый использующий трейт класс — используется в [`MaxBot`](../Bots/MaxBot.php.md) и [`TelegramBot`](../Bots/TelegramBot.php.md).
