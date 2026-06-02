# Price.php

`ST_system\Schemas\Yandex\MedicalFeed\Price` — XML-элемент `<price>` для Яндекс.Здоровье. Используется через поле `price` схемы `Offer`.

## Поля

| Поле | Обяз. | Тип | Описание |
|------|-------|-----|----------|
| `base_price` | да | float | Базовая цена |
| `currency` | да | string | Код валюты (`'RUB'`) |
| `discounts` | нет | array | Массив скидок (каждая: `name` string, `amount` float) |
| `free_appointment` | нет | string[] | Категории бесплатного приёма (например: `'ОМС'`, `'ДМС'`) |

## Использование

Передаётся через поле `price` схемы `Offer`:

```php
'price' => [
    'base_price' => 2500.0,
    'currency'   => 'RUB',
    'discounts'  => [
        ['name' => 'Пенсионерам', 'amount' => 10.0],
    ],
    'free_appointment' => ['ОМС'],
],
```

Полный пример с фидом:

```php
use ST_system\Schemas\Yandex\MedicalFeed;

$feed = (new MedicalFeed())->fill([
    'name'    => 'Клиника Здоровье',
    'url'     => 'https://clinic.ru',
    'offers'  => [
        [
            'id'         => 'offer-1',
            'url'        => 'https://clinic.ru/appointment/1',
            'service_id' => 'svc-1',
            'clinic_id'  => 'clinic-1',
            'doctor_id'  => 'doc-1',
            'speciality' => 'терапевт',
            'price'      => [
                'base_price'       => 1500.0,
                'currency'         => 'RUB',
                'free_appointment' => ['ОМС'],
            ],
        ],
    ],
]);
```
