<!-- DOCGEN:START -->
# Traits

## Папки

- [Events](Events/)

## Файлы

- [HasAttributes.php](HasAttributes.php.md)
- [HasConfig.php](HasConfig.php.md)
- [HasInstance.php](HasInstance.php.md)

<!-- DOCGEN:END -->

Общие примеси (mixins), на которых держится почти весь фреймворк:

- **`HasConfig`** — статическая конфигурация класса (`static::config()`, `applyConfig()` с механизмом дефолтов `@key`). Используется почти каждым конфигурируемым классом (`Access`, `Assets`, `View`, `Debug`, `CacheDriver`, `IntegrationDriver`, `WebClient`, mime-обработчики и т.д.).
- **`HasEvents`** — минимальный pub/sub (`on()`/`fire()`/`trigger()`). Тоже используется почти повсеместно (`Access`, `Debug`, `IntegrationDriver`, `WebClient`) для event-driven точек расширения.
- **`HasInstance`** — ленивый синглтон (`getInstance()`). Используется `Access` и `Debug`.
- **`HasAttributes`** — магический `__get`/маппинг/кеширование атрибутов через `getXAttribute()`-геттеры. Используется `CacheDriver` и `Storage\Resource`/`File`.
