# service.php

> Документация сгенерирована AI-агентом на основе исходного кода.

## 1. Концепция

Микроразметка [schema.org/Service](https://schema.org/Service) в JSON-LD. Зарегистрирована в скопе `schema`.

```php
require_once 'service.php';

$markup = Schema::create('schema.service')->fill([
    'service_type' => 'Пластика лица',
    'description'  => 'Коррекция возрастных изменений.',
    'area_served'  => 'Москва',
    'provider'     => ['type' => 'Hospital', 'name' => 'Клиника'],
    'offers'       => ['price' => '2000', 'price_currency' => 'RUB'],
]);
echo $markup->print();
```

## 2. Поля

`service_type` (req), `name`, `description`, `url`, `image`, `area_served`, `provider` (@provider: type, name, address), `offers` (@offer: price, price_currency), `has_offer_catalog` (@offer-catalog: name, items[]).