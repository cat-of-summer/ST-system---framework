# Offer.php

`ST_system\Schemas\Yandex\MedicalFeed\Offer` — XML-элемент `<offer>` для Яндекс.Здоровье. Связывает услугу, клинику и врача. Используется через поле `offers` схемы `MedicalFeed`.

## Поля

| Поле | Обяз. | Тип | Описание |
|------|-------|-----|----------|
| `id` | да | string | Уникальный ID оффера |
| `url` | да | url | URL страницы записи на приём |
| `service_id` | да | string | ID услуги (из `services`) |
| `clinic_id` | да | string | ID клиники (из `clinics`) |
| `doctor_id` | да | string | ID врача (из `doctors`) |
| `speciality` | да | string | Специализация врача (строго из списка Яндекс) |
| `oms` | нет | bool | Принимается по ОМС |
| `online_schedule` | нет | bool | Онлайн-запись доступна |
| `appointment` | нет | bool | Запись на приём доступна |
| `price` | нет | Price | Стоимость (вложенная схема) |
| `children_appointment` | нет | bool | Приём детей |
| `adult_appointment` | нет | bool | Приём взрослых |
| `house_call` | нет | bool | Вызов на дом |
| `telemed` | нет | bool | Телемедицина |
| `is_base_service` | нет | bool | Базовая услуга специальности |

> `speciality` — значение из закрытого списка (~170 специальностей, например: `'терапевт'`, `'кардиолог'`, `'невролог'`). Полный список см. в `ST_system/Schemas/Yandex/MedicalFeed/Offer.php`.

## Использование

```php
use ST_system\Schemas\Yandex\MedicalFeed;

$feed = (new MedicalFeed())->fill([
    'name'     => 'Клиника Здоровье',
    'url'      => 'https://clinic.ru',
    'services' => [['id' => 'svc-1', 'name' => 'Консультация терапевта']],
    'clinics'  => [['id' => 'clinic-1', 'name' => 'Клиника на Ленина', 'url' => 'https://clinic.ru/lenina']],
    'doctors'  => [['id' => 'doc-1', 'name' => 'Иванов И.И.', 'url' => 'https://clinic.ru/doctors/ivanov']],
    'offers'   => [
        [
            'id'                => 'offer-1',
            'url'               => 'https://clinic.ru/appointment/1',
            'service_id'        => 'svc-1',
            'clinic_id'         => 'clinic-1',
            'doctor_id'         => 'doc-1',
            'speciality'        => 'терапевт',
            'oms'               => true,
            'appointment'       => true,
            'adult_appointment' => true,
            'price'             => [
                'base_price' => 1500.0,
                'currency'   => 'RUB',
            ],
        ],
    ],
]);

echo $feed->print();
```
