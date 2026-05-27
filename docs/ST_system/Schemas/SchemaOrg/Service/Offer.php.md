# Offer.php

`ST_system\Schemas\SchemaOrg\Service\Offer` — Schema.org предложение с ценой. Используется внутри `Service` через поле `offers`. Экспортирует данные через `toArray()`.

## Поля

| Поле | Обяз. | Тип | Описание |
|------|-------|-----|----------|
| `price` | да | string | Цена (строка, например `'2500'`) |
| `price_currency` | да | string | Код валюты (`'RUB'`, `'USD'`) |
| `url` | нет | url | URL страницы с ценой |
| `availability` | нет | string | Доступность (`'https://schema.org/InStock'`) |
| `valid_through` | нет | string | Дата окончания акции (`'2024-12-31'`) |

## Использование

Как правило передаётся через поле `offers` схемы `Service`:

```php
use ST_system\Schemas\SchemaOrg\Service;

$service = (new Service())->fill([
    'service_type' => 'MedicalTherapy',
    'name'         => 'Консультация терапевта',
    'offers' => [
        'price'          => '1500',
        'price_currency' => 'RUB',
        'availability'   => 'https://schema.org/InStock',
        'url'            => 'https://clinic.ru/services/therapist',
    ],
]);
```

Или напрямую:

```php
use ST_system\Schemas\SchemaOrg\Service\Offer;

$offer = (new Offer())->fill([
    'price'          => '1500',
    'price_currency' => 'RUB',
]);

$arr = $offer->toArray();
// ['@type' => 'Offer', 'price' => '1500', 'priceCurrency' => 'RUB']
```
