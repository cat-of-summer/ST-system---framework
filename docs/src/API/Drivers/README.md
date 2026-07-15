<!-- DOCGEN:START -->
# Drivers

## Папки

- [AI](AI/)
- [Acquiring](Acquiring/)
- [Bots](Bots/)
- [CRM](CRM/)
- [Geo](Geo/)
- [Parsers](Parsers/)
- [Traits](Traits/)

## Файлы

- [Isdayoff.php](Isdayoff.php.md)
- [Sdek.php](Sdek.php.md)
- [SmartCaptcha.php](SmartCaptcha.php.md)
- [SmsRu.php](SmsRu.php.md)
- [Telegraph.php](Telegraph.php.md)

<!-- DOCGEN:END -->

Драйверы общего назначения, лежащие прямо в этой директории (все `extends IntegrationDriver`, см. `../IntegrationDriver.php.md`):

- **`Isdayoff`** — календарь рабочих/выходных/праздничных дней (isdayoff.ru).
- **`Sdek`** — интеграция службы доставки СДЭК.
- **`SmartCaptcha`** — Яндекс SmartCaptcha (клиентский виджет + серверная верификация токена).
- **`SmsRu`** — SMS-шлюз sms.ru.
- **`Telegraph`** — публикация страниц через Telegra.ph.

Более специализированные группы драйверов вынесены в поддиректории:

- **`Acquiring/`** — платёжные шлюзы (CloudPayments, Robokassa, T-Банк).
- **`CRM/`** — CRM-интеграции (Битрикс24, RetailCRM).
- **`AI/`** — AI/LLM-провайдеры (OpenAI-совместимые чат-модели, Mistral).
- **`Bots/`** — мессенджер-боты (MAX, Telegram, ВКонтакте).
- **`Geo/`** — гео-IP драйверы (используются `Access::handleGeo()`).
- **`Parsers/`** — HTML-скрапинг/парсинг сторонних сайтов.
- **`Traits/`** — общие трейты для драйверов (например HTML→messenger-разметка для ботов).
