<!-- DOCGEN:START -->
# SxGeo.php
<!-- DOCGEN:END -->

`final class SxGeo extends GeoDriver` — один из гео-IP драйверов (`namespace ST_system\API\Drivers\Geo`), наследует `GeoDriver`, резолвится по короткому имени в `Access::handleGeo()`. Реализует поддержку базы **Sypex Geo (SxGeo)**: локальный бинарный файл `.dat` (собственный парсер формата, без внешних зависимостей) с фолбэком на JSON API `api.sypexgeo.net`.

## Как это устроено

- `ID2ISO` — большая константная таблица соответствия внутреннего числового ID страны Sypex Geo коду ISO 3166-1 alpha-2; используется, когда база содержит только информацию по странам (`max_city === 0`).
- `bootCredentials()` сохраняет ключ доступа к `api.sypexgeo.net` (необязателен).
- `downloadUrl()` по умолчанию — `https://sypexgeo.net/files/SxGeoCountry.zip`; `extract()` распаковывает zip (`ZipArchive`) и находит внутри `*.dat`.
- `open()` разбирает бинарный заголовок `SxG` (версия, время сборки, charset, длины индексов, число записей, размеры регионов/городов и т.д.) и загружает индексы/блоки БД в память.
- `lookupLocal()` — переводит IP в внутренний ID через бинарный поиск по индексам (`datGetNum()`), затем либо разбирает полную запись город+регион+страна (`datParseCity()`, если у базы есть городская точность), либо возвращает только код страны из `ID2ISO` (для country-level баз).
- `normalizeApiResponse()` приводит ответ JSON API к общему формату (`country`, `country_code`, `country_name`, `city`, `lat`, `lon`).
- `dbVersion()` — дата сборки базы из поля `time` заголовка.

Публичных методов сверх унаследованных от `GeoDriver` этот класс не добавляет.

## Публичные методы (унаследованы от `GeoDriver`)

- `getDetails(string $ip): array`
- `update(): bool`
- `version(): ?string`

## Пример

```php
use ST_system\API\Drivers\Geo\SxGeo;

$sxgeo = SxGeo::create('');     // ключ нужен только для обращений к api.sypexgeo.net
$sxgeo->update();               // скачать и распаковать SxGeoCountry.dat

$details = $sxgeo->getDetails('77.88.55.60');
// ['country' => 'RU', 'country_code' => 'RU', ...]
```
