# Service.php

`ST_system\Schemas\SchemaOrg\Service` — Schema.org разметка медицинской/коммерческой услуги. Выводит `<script type="application/ld+json">` с типом `Service`.

## Поля

| Поле | Обяз. | Тип | Описание |
|------|-------|-----|----------|
| `service_type` | да | string | Тип услуги (`'MedicalTherapy'`, `'ConsultationService'` и др.) |
| `name` | нет | string | Название услуги |
| `description` | нет | string | Описание |
| `url` | нет | url | URL страницы услуги |
| `image` | нет | url | URL изображения |
| `area_served` | нет | string | Зона обслуживания (`'Москва'`, `'Россия'`) |
| `provider` | нет | Provider | Поставщик услуги (вложенная схема) |
| `offers` | нет | Offer | Предложение с ценой (вложенная схема) |
| `has_offer_catalog` | нет | OfferCatalog | Каталог предложений (вложенная схема) |

## Использование

```php
use ST_system\Schemas\SchemaOrg\Service;

$service = (new Service())->fill([
    'service_type' => 'MedicalTherapy',
    'name'         => 'УЗИ брюшной полости',
    'description'  => 'Ультразвуковое исследование органов брюшной полости',
    'url'          => 'https://clinic.ru/services/uzi',
    'area_served'  => 'Москва',
    'provider'     => [
        'name'      => 'Клиника Здоровье',
        'url'       => 'https://clinic.ru',
        'telephone' => '+7 495 000-00-00',
    ],
    'offers' => [
        'price'          => '2500',
        'price_currency' => 'RUB',
        'url'            => 'https://clinic.ru/services/uzi',
    ],
]);

echo $service->print();
// <script type="application/ld+json">{"@context":"https://schema.org","@type":"Service",...}</script>
```
