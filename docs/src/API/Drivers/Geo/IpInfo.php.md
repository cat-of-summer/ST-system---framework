<!-- DOCGEN:START -->
# IpInfo.php
<!-- DOCGEN:END -->

`final class IpInfo extends GeoDriver` — один из гео-IP драйверов (`namespace ST_system\API\Drivers\Geo`), наследует `GeoDriver`, резолвится по короткому имени в `Access::handleGeo()`. Реализует обращение к API сервиса **ipinfo.io**. В отличие от `GeoIP2`/`SxGeo`, не поддерживает локальную базу — работает только через HTTP API, с включённым по умолчанию кэшированием ответов (`cache.use = true`).

## Как это устроено

Класс полностью переопределяет `__init()` (не вызывает `parent::__init()`), так как у него другой конструктор и другой зарегистрированный метод:

- Конструктор `__construct(string $token, string $service = 'lite')` — `service` валидируется правилом `string|in:lite|default:lite` (`Rule::create(...)->throwable()->check(...)`).
- Событие `build_url` подставляет `token` и `service` в URL: `{endpoint}/{service}/{ip}`.
- Зарегистрирован собственный метод `getDetails` (а не `lookup`, как в базовом `GeoDriver`) с параметром `ip` (`string|required|default:me`) и `cache_ttl => -1`.

## Публичные методы

- `getDetails(string $ip = 'me'): array` — переопределяет сигнатуру `GeoDriver::getDetails()` (необязательный `$ip`, по умолчанию `'me'` — IP-адрес самого запроса). Возвращает ответ ipinfo.io как есть (нормализация под общий формат не выполняется).

`update()` и `version()`, унаследованные от `GeoDriver`, для этого драйвера бессмысленны — локальной базы нет, `downloadUrl()`/`dbFilename()` не переопределены, поэтому `update()` всегда вернёт `false`.

## Пример

```php
use ST_system\API\Drivers\Geo\IpInfo;

$ipinfo = IpInfo::create('your_token');           // service по умолчанию 'lite'
$details = $ipinfo->getDetails('8.8.8.8');
$self    = $ipinfo->getDetails();                 // 'me' — IP текущего запроса
```
