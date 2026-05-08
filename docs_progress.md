# Прогресс документирования ST_system

Обновляется автоматически. Формат: `[x]` — заполнен, `[ ]` — не заполнен.

---

## Ядро системы

- [x] `ST_system/Rule.php` → `docs/ST_system/Rule.php.md` *(эталон, уже готов)*
- [ ] `ST_system/Main.php` → `docs/ST_system/Main.php.md`
- [ ] `ST_system/Config.php` → `docs/ST_system/Config.php.md`
- [ ] `ST_system/Debug.php` → `docs/ST_system/Debug.php.md`
- [ ] `ST_system/Access.php` → `docs/ST_system/Access.php.md`
- [ ] `ST_system/Loader.php` → `docs/ST_system/Loader.php.md`
- [ ] `ST_system/Menu.php` → `docs/ST_system/Menu.php.md`
- [ ] `ST_system/Schema.php` → `docs/ST_system/Schema.php.md`
- [ ] `ST_system/Assets.php` → `docs/ST_system/Assets.php.md`
- [ ] `ST_system/Daemon.php` → `docs/ST_system/Daemon.php.md`

## Трейты (ST_system/Traits/)

- [ ] `HasAttributes.php` → `docs/ST_system/Traits/HasAttributes.php.md`
- [ ] `HasConfig.php` → `docs/ST_system/Traits/HasConfig.php.md`
- [ ] `HasEvents.php` → `docs/ST_system/Traits/HasEvents.php.md`

## HTTP-слой (ST_system/HTTP/)

- [ ] `Request.php` → `docs/ST_system/HTTP/Request.php.md`
- [ ] `Response.php` → `docs/ST_system/HTTP/Response.php.md`
- [ ] `Route.php` → `docs/ST_system/HTTP/Route.php.md`

## API-ядро (ST_system/API/)

- [ ] `IntegrationDriver.php` → `docs/ST_system/API/IntegrationDriver.php.md`
- [ ] `Router.php` → `docs/ST_system/API/Router.php.md`

## Кэш (ST_system/Cache/)

- [ ] `CacheDriver.php` → `docs/ST_system/Cache/CacheDriver.php.md`
- [ ] `Manager.php` → `docs/ST_system/Cache/Manager.php.md`
- [ ] `Drivers/DatabaseCacheDriver.php` → `docs/ST_system/Cache/Drivers/DatabaseCacheDriver.php.md`
- [ ] `Drivers/FileSystemCacheDriver.php` → `docs/ST_system/Cache/Drivers/FileSystemCacheDriver.php.md`
- [ ] `Drivers/RedisCacheDriver.php` → `docs/ST_system/Cache/Drivers/RedisCacheDriver.php.md`
- [ ] `Drivers/Database/DatabaseAdapterInterface.php` → `docs/ST_system/Cache/Drivers/Database/DatabaseAdapterInterface.php.md`
- [ ] `Drivers/Database/MysqlAdapter.php` → `docs/ST_system/Cache/Drivers/Database/MysqlAdapter.php.md`
- [ ] `Drivers/Database/PostgresAdapter.php` → `docs/ST_system/Cache/Drivers/Database/PostgresAdapter.php.md`
- [ ] `Drivers/Redis/RedisAdapterInterface.php` → `docs/ST_system/Cache/Drivers/Redis/RedisAdapterInterface.php.md`
- [ ] `Drivers/Redis/PhpRedisAdapter.php` → `docs/ST_system/Cache/Drivers/Redis/PhpRedisAdapter.php.md`
- [ ] `Drivers/Redis/PredisAdapter.php` → `docs/ST_system/Cache/Drivers/Redis/PredisAdapter.php.md`

## API Drivers — базовые (ST_system/API/Drivers/)

- [ ] `Isdayoff.php` → `docs/ST_system/API/Drivers/Isdayoff.php.md`
- [ ] `Sdek.php` → `docs/ST_system/API/Drivers/Sdek.php.md`
- [ ] `SmartCaptcha.php` → `docs/ST_system/API/Drivers/SmartCaptcha.php.md`
- [ ] `SmsRu.php` → `docs/ST_system/API/Drivers/SmsRu.php.md`
- [ ] `Telegraph.php` → `docs/ST_system/API/Drivers/Telegraph.php.md`
- [ ] `Traits/HasHTMLRules.php` → `docs/ST_system/API/Drivers/Traits/HasHTMLRules.php.md`

## Acquiring (ST_system/API/Drivers/Acquiring/)

- [ ] `CloudPayments.php` → `docs/ST_system/API/Drivers/Acquiring/CloudPayments.php.md`
- [ ] `Robokassa.php` → `docs/ST_system/API/Drivers/Acquiring/Robokassa.php.md`
- [ ] `TBank.php` → `docs/ST_system/API/Drivers/Acquiring/TBank.php.md`

## AI-драйверы (ST_system/API/Drivers/AI/)

- [ ] `OpenAICompatibleDriver.php` → `docs/ST_system/API/Drivers/AI/OpenAICompatibleDriver.php.md`
- [ ] `Mistral.php` → `docs/ST_system/API/Drivers/AI/Mistral.php.md`

## Боты (ST_system/API/Drivers/Bots/)

- [ ] `TelegramBot.php` → `docs/ST_system/API/Drivers/Bots/TelegramBot.php.md`
- [ ] `MaxBot.php` → `docs/ST_system/API/Drivers/Bots/MaxBot.php.md`
- [ ] `VkBot.php` → `docs/ST_system/API/Drivers/Bots/VkBot.php.md`

## CRM-драйверы (ST_system/API/Drivers/CRM/)

- [ ] `Bitrix24.php` → `docs/ST_system/API/Drivers/CRM/Bitrix24.php.md`
- [ ] `RentalCRM.php` → `docs/ST_system/API/Drivers/CRM/RentalCRM.php.md`

## Storage (ST_system/Storage/)

- [ ] `File.php` → `docs/ST_system/Storage/File.php.md`
- [ ] `Mimes/Mime.php` → `docs/ST_system/Storage/Mimes/Mime.php.md`
- [ ] `Mimes/CssMime.php` → `docs/ST_system/Storage/Mimes/CssMime.php.md`
- [ ] `Mimes/FontMime.php` → `docs/ST_system/Storage/Mimes/FontMime.php.md`
- [ ] `Mimes/ImageMime.php` → `docs/ST_system/Storage/Mimes/ImageMime.php.md`
- [ ] `Mimes/JavaScriptMime.php` → `docs/ST_system/Storage/Mimes/JavaScriptMime.php.md`
- [ ] `Mimes/JsonMime.php` → `docs/ST_system/Storage/Mimes/JsonMime.php.md`
- [ ] `Mimes/SvgMime.php` → `docs/ST_system/Storage/Mimes/SvgMime.php.md`
- [ ] `Mimes/TextPlainMime.php` → `docs/ST_system/Storage/Mimes/TextPlainMime.php.md`
- [ ] `Mimes/Traits/Minifiable.php` → `docs/ST_system/Storage/Mimes/Traits/Minifiable.php.md`

## Console (ST_system/Console/)

- [ ] `Command.php` → `docs/ST_system/Console/Command.php.md`
- [ ] `Kernel.php` → `docs/ST_system/Console/Kernel.php.md`

## CensorText (ST_system/CensorText/)

- [ ] `CensorText.php` → `docs/ST_system/CensorText/CensorText.php.md`

## Schemas (ST_system/Schemas/)

- [ ] `yandex-medical-feed.php` → `docs/ST_system/Schemas/yandex-medical-feed.php.md`
- [ ] `SchemaMarkup/schema.org/item-list.php` → `docs/ST_system/Schemas/SchemaMarkup/schema.org/item-list.php.md`
- [ ] `SchemaMarkup/schema.org/medical-procedure.php` → `docs/ST_system/Schemas/SchemaMarkup/schema.org/medical-procedure.php.md`
- [ ] `SchemaMarkup/schema.org/service.php` → `docs/ST_system/Schemas/SchemaMarkup/schema.org/service.php.md`

---

*Всего: 61 файл. Последнее обновление: фаза 0 (инициализация)*
