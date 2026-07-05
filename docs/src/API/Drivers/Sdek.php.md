# Sdek

## 1. Концепция

Драйвер [SDEK API v2](https://api.cdek.ru/v2/). Автоматически авторизуется при создании и подставляет Bearer-токен во все запросы. OAuth-токен кэшируется на 3600 с.

```php
$sdek = Sdek::create([
    'client_id'     => 'EMscd6r9JnFiQ3bLoyjJY6eM78JV2daB',
    'client_secret' => 'PjLZkKBHEiLK3YsjtNrt3TGNG0ahs3kG',
]);

$cities = $sdek->call('location/suggest/cities', ['name' => 'Москва']);
$calc   = $sdek->call('calculator/tariff', [
    'tariff_code'   => 136,
    'from_location' => ['code' => 44],
    'to_location'   => ['code' => 270],
    'packages'      => [['weight' => 1000]],
]);
```

## 2. Публичные методы

### `static create(array $PARAMS): static`
Параметры: `client_id`, `client_secret`.

### `call(string $method, array $params): mixed`

| Метод | Описание |
|---|---|
| `location/suggest/cities` | Поиск городов. `name` — обязат. |
| `location/cities` | Список городов (пагинация). |
| `deliverypoints` | ПВЗ/постаматы. `type`: `PVZ`, `ALL`, `POSTAMAT`. |
| `calculator/tariff` | Расчёт доставки по тарифу. |
| `calculator/alltariffs` | Расчёт по всем тарифам. |
| `orders` | Информация об отправлении. `cdek_number` — обязат. |.php
