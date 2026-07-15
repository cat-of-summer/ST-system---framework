<!-- DOCGEN:START -->
# Acquiring

## Файлы

- [CloudPayments.php](CloudPayments.php.md)
- [Robokassa.php](Robokassa.php.md)
- [TBank.php](TBank.php.md)

<!-- DOCGEN:END -->

Драйверы в этой директории объединяет одно назначение — интеграция с платёжными шлюзами (эквайринг): все три `final class ... extends IntegrationDriver` из `ST_system\API\Drivers\Acquiring`, декларирующие свои эндпоинты через `registerMethodsMap()` в `__init()` и добавляющие поверх общего пайплайна `IntegrationDriver` специфичную для конкретного шлюза авторизацию/подпись запроса и разбор ответа.

- **`CloudPayments`** — платёжный шлюз CloudPayments, Basic-авторизация по `public_id`/`api_secret`, методы для заказов (`orders/create`/`orders/cancel`), поиска платежей и настройки вебхук-уведомлений.
- **`Robokassa`** — платёжный шлюз Robokassa, построен вокруг подписанных ссылок на оплату (MD5/SHA-подпись из `merchant_login`/`password1`/`password2`) и верификации Result-уведомлений; серверные вызовы (`Merchant/Recurring`, `OpStateExt`) — вторичны.
- **`TBank`** — эквайринг Т‑Банка (бывш. Тинькофф), каждый запрос и каждое webhook-уведомление подписываются токеном SHA-256 от отсортированных параметров запроса (`terminal_key`/`password`).
