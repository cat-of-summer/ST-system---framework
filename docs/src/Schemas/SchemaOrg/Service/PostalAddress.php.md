# PostalAddress.php

`ST_system\Schemas\SchemaOrg\Service\PostalAddress` — Schema.org почтовый адрес. Используется внутри `Provider` через поле `address`. Экспортирует данные через `toArray()`.

## Поля

Все поля необязательны.

| Поле | Тип | Описание |
|------|-----|----------|
| `street_address` | string | Улица и дом (`'ул. Ленина, 10'`) |
| `address_locality` | string | Город (`'Москва'`) |
| `postal_code` | string | Почтовый индекс (`'125009'`) |
| `address_country` | string | Страна (`'RU'`) |

## Использование

Как правило передаётся через поле `address` схемы `Provider`:

```php
use ST_system\Schemas\SchemaOrg\Service;

$service = (new Service())->fill([
    'service_type' => 'MedicalTherapy',
    'name'         => 'Консультация',
    'provider' => [
        'name'    => 'Клиника Здоровье',
        'address' => [
            'street_address'   => 'ул. Ленина, 10',
            'address_locality' => 'Москва',
            'postal_code'      => '125009',
            'address_country'  => 'RU',
        ],
    ],
]);
```

Или напрямую:

```php
use ST_system\Schemas\SchemaOrg\Service\PostalAddress;

$addr = (new PostalAddress())->fill([
    'street_address'   => 'ул. Ленина, 10',
    'address_locality' => 'Москва',
]);

$arr = $addr->toArray();
// ['@type' => 'PostalAddress', 'streetAddress' => 'ул. Ленина, 10', 'addressLocality' => 'Москва']
```
