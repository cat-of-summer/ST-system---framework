<!-- DOCGEN:START -->
# GeoDriver.php
<!-- DOCGEN:END -->

`abstract class GeoDriver extends IntegrationDriver` — базовый класс для всех гео-IP драйверов (`namespace ST_system\API\Drivers\Geo`). Сам по себе не используется — от него наследуются конкретные драйверы (`GeoIP2`, `IpInfo`, `SxGeo`), которые резолвятся по короткому имени из реестра `handleGeo.drivers.available` и вызываются через `Access::handleGeo()` (`$driver::create($token)->getDetails($ip)`).

## Что делает

`GeoDriver` реализует общую логику "локальная база или API":

- `mode` (`auto` | `local` | `api`, конфиг) выбирает источник данных:
  - `auto` — сначала пробует локальную базу (`resolveDbPath()`), при отсутствии файла пытается скачать её через `update()`, если не вышло — идёт в API;
  - `local` — только локальная база, без похода в API;
  - `api` — всегда обращается к внешнему API через `call('lookup', ...)`.
- Событие `__construct` прокидывает переданные учётные данные в `bootCredentials()`.
- Событие `build_url` подставляет IP в URL через `apiUrl($ip)`.
- Событие `before_curl_init` добавляет заголовок `Authorization`, если `apiAuthHeader()` вернул непустую строку.
- Метод `lookup` зарегистрирован с `cache_ttl => -1` (кэшируется бессрочно, если включено кэширование).

Подклассы переопределяют защищённые хуки под свой сервис/формат базы: `bootCredentials()`, `apiUrl()`, `apiAuthHeader()`, `normalizeApiResponse()`, `downloadUrl()`, `extract()`, `dbFilename()`, `lookupLocal()`, `dbVersion()`.

## Публичные методы

- `getDetails(string $ip): array` — вернуть гео-данные по IP (локальная база или API, в зависимости от `mode`); нормализованный ответ обычно содержит ключи вида `country`, `country_code`, `country_name`, `city`, `lat`, `lon` (набор зависит от конкретного драйвера).
- `update(): bool` — скачать/обновить локальную базу через `downloadUrl()` + `extract()`, сохранить метаданные версии (`geo_version`, `fetched_at`).
- `version(): ?string` — версия текущей локальной базы (из метаданных файла либо через `dbVersion()`).

## Пример

```php
use ST_system\API\Drivers\Geo\GeoIP2;

$driver = GeoIP2::create('account_id:license_key');
$driver->update();                     // подтянуть/обновить локальную .mmdb базу
$details = $driver->getDetails('8.8.8.8');
$ver = $driver->version();
```

Через `Access::handleGeo()` короткое имя резолвится из реестра:

```php
Access::handleGeo([
    'driver' => 'geoip2',        // короткое имя из handleGeo.drivers.available
    'token'  => 'account_id:license_key',
    'ip'     => '8.8.8.8',
]);
```
