# IpInfo


## 1. Концепция

Драйвер геолокации по IP через [ipinfo.io](https://ipinfo.io). **Только API**, без локальной БД.
Наследует [`GeoDriver`](GeoDriver.php.md); файловые методы (`update()`/`version()`) — no-op.
Драйвер по умолчанию в [`Access::handleGeo()`](../../../Access.php.md) (`handleGeo.drivers.default = 'ipinfo'`).

```php
use ST_system\API\Drivers\Geo\IpInfo;

$details = IpInfo::create('ваш-token')->getDetails('8.8.8.8');
// ['country' => 'US', 'city' => 'Mountain View', ...] — как отдаёт ipinfo.io
```

## 2. Конфигурация

| Ключ | Умолч. | Описание |
|------|--------|----------|
| `endpoint` | `https://api.ipinfo.io/` | Базовый URL |
| `cache.use` | `true` | Кэшировать ответы API |

## 3. Методы

### `static create(string $token, string $service = 'lite'): static`
`$service` — тариф ipinfo (`lite` по умолчанию). `$token` подставляется в каждый запрос.

### `getDetails(string $ip = 'me'): array`
Гео-данные по IP (`me` — собственный IP сервера по версии ipinfo). Ответ кэшируется бессрочно
(`cache_ttl = -1`), пока не сброшен `purge()`.

```php
$geo = IpInfo::create(getenv('IPINFO_TOKEN'));

$me   = $geo->getDetails();          // свой IP
$user = $geo->getDetails('1.1.1.1'); // конкретный IP
```

## 4. Через Access::handleGeo()

```php
Access::handleGeo([
    'driver'     => 'ipinfo',
    'token'      => getenv('IPINFO_TOKEN'),
    'black_list' => ['country' => 'CN'],
]);
```
