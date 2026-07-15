<!-- DOCGEN:START -->
# Cache

## Папки

- [Drivers](Drivers/)

## Файлы

- [CacheDriver.php](CacheDriver.php.md)
- [CacheManager.php](CacheManager.php.md)

<!-- DOCGEN:END -->

Система кеширования фреймворка. `CacheManager` — фасад/точка входа, которым пользуется весь остальной код (`IntegrationDriver`, `WebClient`, `File`, `Access`, `Assets`, `View`): `CacheManager::make($key, $config)` выбирает и инстанцирует нужный бекенд-драйвер по `config('driver')`, дальше `get()`/`set()`/`remember()`/`getMeta()`/`setMeta()`/`isValid()`/`purge()`/`purgeBase()` делегируются выбранному драйверу. `CacheDriver` — абстрактный базовый класс для всех бекендов; конкретные реализации (файловая система, БД, Memcached, Redis, сессия) лежат в поддиректории `Drivers/`. См. `CacheManager.php.md` за полным API и `CacheDriver.php.md` за тем, как написать новый бекенд.
