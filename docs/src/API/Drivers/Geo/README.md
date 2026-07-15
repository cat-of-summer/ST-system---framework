<!-- DOCGEN:START -->
# Geo

## Файлы

- [GeoDriver.php](GeoDriver.php.md)
- [GeoIP2.php](GeoIP2.php.md)
- [IpInfo.php](IpInfo.php.md)
- [SxGeo.php](SxGeo.php.md)

<!-- DOCGEN:END -->

## Описание

Гео-IP драйверы: определение страны/города по IP-адресу. Все наследуют абстрактный `GeoDriver` (сам расширяет `API\IntegrationDriver`) и резолвятся по короткому имени из реестра конфига `handleGeo.drivers.available` внутри `Access::handleGeo()`, которая вызывает их единообразно — `$driver::create($token)->getDetails($ip)`.

- **GeoDriver** — общая логика выбора источника данных (`mode`: `auto`/`local`/`api`), скачивания и версионирования локальной базы (`update()`, `version()`), плюс хуки для подключения конкретного сервиса/формата.
- **GeoIP2** — MaxMind GeoIP2/GeoLite2: Web Service API + локальная `.mmdb`-база (собственный бинарный парсер формата).
- **IpInfo** — API-сервис ipinfo.io; работает только через HTTP, без локальной базы.
- **SxGeo** — база Sypex Geo: локальный `.dat`-файл (собственный парсер) + фолбэк на JSON API `api.sypexgeo.net`.

Каждый драйвер приводит ответ к общему формату (`country`, `country_code`, `country_name`, `city`, `lat`, `lon` — набор полей зависит от точности конкретной базы/сервиса).
