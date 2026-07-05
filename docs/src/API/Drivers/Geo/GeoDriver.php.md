# GeoDriver


## 1. Концепция

`GeoDriver` — **абстрактный базовый класс** для драйверов геолокации по IP
(`ST_system\API\Drivers\Geo`). Наследует `ST_system\API\IntegrationDriver`, поэтому создаётся
через `::create(...)` и умеет работать по REST API (curl-flow). Сверх этого добавляет единый
шаблон для драйверов с **собственной локальной БД** (SxGeo `.dat`, MaxMind `.mmdb`):
скачивание/обновление файла БД и определение по нему оффлайн.

Реализации: [`IpInfo`](IpInfo.php.md) (только API), [`SxGeo`](SxGeo.php.md),
[`GeoIP2`](GeoIP2.php.md). Драйвер выбирается в `Access::handleGeo()` через реестр
`handleGeo.drivers.available`.

Все драйверы возвращают **нормализованный** массив с общими ключами:

```php
['country' => 'RU', 'country_code' => 'RU', 'country_name' => 'Russia', 'city' => 'Moscow', 'lat' => 55.75, 'lon' => 37.61]
```

(набор ключей зависит от типа БД/ответа; гарантирован минимум `country` при успехе).

## 2. Публичный контракт

### `static create(...$args): static`
Фабрика (из `IntegrationDriver`). Первым аргументом — учётные данные драйвера (API-ключ / лицензия);
для локального режима можно опустить.

### `getDetails(string $ip): array`
Определяет гео-данные. Логика зависит от `config('mode')`:
- `local` — только локальная БД (пусто, если её нет);
- `api` — только REST API;
- `auto` (умолч.) — локальная БД, если доступна (при отсутствии — ленивое `update()`), иначе API.

### `update(): bool`
Скачивает архив БД (`downloadUrl()`) через `ST_system\Storage\File`, распаковывает в
`assets/geo/` (или системный temp, если `assets/` только для чтения — напр. установка через
composer, где `assets/` вырезан из dist) и через нативный механизм мета-данных `File::setMeta()`
записывает версию/время загрузки. API-only драйверы возвращают `false`.

### `version(): ?string`
Дата сборки локальной БД (`Y.m.d`) из мета-данных `File` либо из заголовка самой БД. `null`, если
БД не используется.

## 3. Конфигурация

Общие ключи (драйверы дополняют своими через `array_merge(parent::getDefaultConfig(), [...])`):

| Ключ | Умолч. | Описание |
|------|--------|----------|
| `mode` | `'auto'` | `auto` / `local` / `api` |
| `db_path` | `''` | Явный путь к файлу БД (иначе — `assets/geo/` или скачанная копия) |
| `db_url` | `''` | URL архива БД для `update()` |
| `cache.use` | `false` | Кэш ответов API (`IntegrationDriver`) |

```php
SxGeo::setConfig(['mode' => 'local', 'db_path' => '/data/SxGeoCity.dat']);
GeoIP2::setConfig(['edition' => 'GeoLite2-City', 'service' => 'city']);
```

## 4. Как написать свой гео-драйвер

Наследуйте `GeoDriver` и переопределите нужные хуки (все имеют пустые дефолты, поэтому
API-only драйверу файловые методы не нужны):

| Хук | Назначение |
|-----|-----------|
| `bootCredentials(string $c)` | разобрать ключ/лицензию из `create()` |
| `apiUrl(string $ip): string` | URL REST-запроса |
| `apiAuthHeader(): ?string` | значение заголовка `Authorization` (или `null`) |
| `normalizeApiResponse(array): array` | ответ API → нормализованный формат |
| `dbFilename(): string` | имя файла локальной БД (`''` = API-only) |
| `downloadUrl(): ?string` | URL архива для `update()` |
| `extract(string $archive, string $dir): ?string` | распаковать архив → путь к БД |
| `lookupLocal(string $ip, string $path): array` | определить по локальной БД |
| `dbVersion(string $path): ?string` | дата сборки БД |

Внутренняя обвязка (`getDetails`/`update`/`version`, скачивание через `File`, резолв пути,
запись мета) уже реализована в базе.
