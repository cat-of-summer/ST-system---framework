# Provider.php

`ST_system\Schemas\SchemaOrg\Service\Provider` — Schema.org поставщик услуги (организация или человек). Используется внутри `Service` через поле `provider`. Экспортирует данные через `toArray()`.

## Поля

| Поле | Обяз. | Тип | Описание |
|------|-------|-----|----------|
| `type` | нет | string | Schema.org тип (`'Organization'`, `'Physician'`). Умолч: `'Organization'` |
| `name` | да | string | Название организации или имя врача |
| `url` | нет | url | URL сайта |
| `telephone` | нет | string | Телефон (`'+7 495 000-00-00'`) |
| `address` | нет | PostalAddress | Почтовый адрес (вложенная схема) |

## Использование

Как правило передаётся через поле `provider` схемы `Service`:

```php
use ST_system\Schemas\SchemaOrg\Service;

$service = (new Service())->fill([
    'service_type' => 'MedicalTherapy',
    'name'         => 'Консультация кардиолога',
    'provider' => [
        'type'      => 'Physician',
        'name'      => 'Иванов Иван Иванович',
        'url'       => 'https://clinic.ru/doctors/ivanov',
        'telephone' => '+7 495 000-00-00',
        'address'   => [
            'street_address'   => 'ул. Ленина, 10',
            'address_locality' => 'Москва',
        ],
    ],
]);

echo $service->print();
```
