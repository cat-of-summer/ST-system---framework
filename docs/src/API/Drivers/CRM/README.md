<!-- DOCGEN:START -->
# CRM

## Файлы

- [Bitrix24.php](Bitrix24.php.md)
- [RentalCRM.php](RentalCRM.php.md)

<!-- DOCGEN:END -->

Драйверы в этой директории объединяет назначение — интеграция с CRM-системами: оба `final class ... extends IntegrationDriver` из `ST_system\API\Drivers\CRM`, оборачивающие REST API конкретной CRM в декларативную карту методов (`registerMethodsMap()`), с валидацией полей сущностей (контакты, сделки, заказы, клиенты, задачи) через `Rule`.

- **`Bitrix24`** — REST API Битрикс24 (через вебхук портала), с расширяемыми через `extendFields()`/`extendParams()` схемами полей для `crm.contact.add`/`crm.deal.add` и собственными алиасами правил (`date`, `bool`, `multifield`).
- **`RentalCRM`** — RetailCRM (API v5) под поддоменом компании, с автоматической аутентификацией запросов по `api_key` и JSON-сериализацией вложенных сущностей (`order`, `customer`, `task`) перед отправкой.
