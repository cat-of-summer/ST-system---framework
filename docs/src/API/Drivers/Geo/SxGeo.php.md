# SxGeo


## 1. Концепция

Драйвер геолокации [Sypex Geo](https://sypexgeo.net). Наследует [`GeoDriver`](GeoDriver.php.md)
и умеет работать в двух режимах (встроенный ридер формата `.dat` — прямо в классе, без внешних
зависимостей):

- **local** — оффлайн-определение по локальной БД `.dat` (в комплекте `assets/geo/SxGeo.dat` —
  country-БД; можно подложить `SxGeoCity.dat`);
- **api** — REST `https://api.sypexgeo.net/[key]/json/{ip}` (10k запросов/мес без ключа);
- **auto** (умолч.) — локальная БД, если есть, иначе API.

```php
use ST_system\API\Drivers\Geo\SxGeo;

// оффлайн, без ключа:
$geo = SxGeo::create();
$geo->getDetails('77.88.55.60'); // ['country' => 'RU', 'country_code' => 'RU']
```

## 2. Конфигурация

| Ключ | Умолч. | Описание |
|------|--------|----------|
| `mode` | `'auto'` | `auto` / `local` / `api` |
| `endpoint` | `https://api.sypexgeo.net` | Базовый URL API |
| `db_path` | `''` | Явный путь к `.dat` (иначе `assets/geo/SxGeo.dat` или скачанная копия) |
| `db_url` | `https://sypexgeo.net/files/SxGeoCountry.zip` | Архив БД для `update()` |

## 3. Методы

### `static create(string $key = ''): static`
`$key` — API-ключ Sypex Geo (для API-режима; пусто = бесплатный лимит по IP/Referer).
В `local`-режиме не используется.

### `getDetails(string $ip): array`
Нормализованные данные: `country`, `country_code` (для country-БД); для city-БД добавляются
`city`, `lat`, `lon`.

### `update(): bool`
Скачивает `SxGeoCountry.zip` (`db_url`), распаковывает `.dat` в `assets/geo/` (или temp) и пишет
версию в мета `File`. Вернёт `false` при ошибке сети/распаковки.

### `version(): ?string`
Дата сборки БД (`Y.m.d`) из заголовка `.dat`.

```php
$geo = SxGeo::create();

// принудительно обновить локальную БД (например, по крону 2×/мес):
if ($geo->update())
    echo 'SxGeo обновлена до '.$geo->version();

// строго оффлайн-режим по своей city-БД:
SxGeo::setConfig(['mode' => 'local', 'db_path' => '/data/SxGeoCity.dat']);
$city = SxGeo::create()->getDetails('77.88.55.60'); // + city/lat/lon
```

## 4. Через Access::handleGeo()

```php
Access::handleGeo([
    'driver'     => 'sxgeo',                     // оффлайн, без токена
    'black_list' => ['country' => ['CN', 'KP']],
    'onBlackList'=> fn($d) => Access::throw(451),
]);
```

> Файл `SxGeo.dat` лежит в `assets/` и **исключён из composer-dist** (`.gitattributes:
> /assets/ export-ignore`). При установке через composer драйвер докачает БД сам при первом
> обращении (в writable-каталог).
