<!-- DOCGEN:START -->
# GeoIP2.php
<!-- DOCGEN:END -->

`final class GeoIP2 extends GeoDriver` — один из гео-IP драйверов (`namespace ST_system\API\Drivers\Geo`), наследует `GeoDriver`, резолвится по короткому имени в `Access::handleGeo()`. Реализует поддержку базы/сервиса **MaxMind GeoIP2** — как удалённого Web Service API (`geoip.maxmind.com`), так и локальной бинарной базы формата `.mmdb` (GeoLite2/GeoIP2), которую драйвер разбирает самостоятельно, без внешних библиотек.

## Как это устроено

- `bootCredentials($credentials)` принимает либо `"account_id:license_key"`, либо просто `license_key` (тогда `account_id` пустой).
- `apiAuthHeader()` формирует `Basic`-заголовок из `account:license` для запросов к MaxMind API.
- `downloadUrl()` строит URL скачивания базы (`geoip_download`) по `edition` и `license_key`, если `db_url` не задан явно в конфиге.
- `extract()` распаковывает скачанный `tar.gz` (через `PharData`) и находит внутри файл `*.mmdb`.
- `dbFilename()` — `{edition}.mmdb` (по умолчанию `edition = GeoLite2-Country`).
- `lookupLocal()` / `open()` / `mmdbDecode*()` — собственный парсер формата MaxMind DB: бинарный поиск по дереву (`node_count`/`record_size`) по битам IP, затем декодирование data-секции (pointer, string, double, bytes, uint, map, int32, array, boolean, float).
- `normalize()` приводит и локальный, и API-ответ к общему формату (`country`, `country_code`, `country_name`, `city`, `lat`, `lon`).

Публичных методов сверх унаследованных от `GeoDriver` этот класс не добавляет — все переопределения (`bootCredentials`, `apiUrl`, `apiAuthHeader`, `normalizeApiResponse`, `dbFilename`, `downloadUrl`, `extract`, `dbVersion`, `lookupLocal`) защищённые.

## Публичные методы (унаследованы от `GeoDriver`)

- `getDetails(string $ip): array`
- `update(): bool`
- `version(): ?string`

## Пример

```php
use ST_system\API\Drivers\Geo\GeoIP2;

// "account_id:license_key" — для Web Service API и скачивания базы;
// можно передать только license_key.
$geoip2 = GeoIP2::create('123456:abcdef0123456789');

$geoip2->update(); // скачать/обновить локальную GeoLite2-Country.mmdb

$info = $geoip2->getDetails('91.198.174.192');
// ['country' => 'US', 'country_code' => 'US', 'country_name' => 'United States', ...]
```
