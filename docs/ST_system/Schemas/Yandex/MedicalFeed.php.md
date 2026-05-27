# MedicalFeed.php

`ST_system\Schemas\Yandex\MedicalFeed` — корневой XML-фид для Яндекс.Здоровье. Выводит полный XML-документ `<shop version="2.0">` с врачами, клиниками, услугами и офферами.

## Поля

| Поле | Обяз. | Тип | Описание |
|------|-------|-----|----------|
| `name` | да | string | Название магазина/клиники |
| `url` | да | url | URL сайта |
| `date` | нет | string | Дата формирования фида (`Y-m-d H:i`). Умолч: текущая дата |
| `company` | нет | string | Юридическое название компании |
| `picture` | нет | url | URL логотипа |
| `email` | нет | string | Email для связи |
| `doctors` | нет | Doctor[] | Массив врачей |
| `clinics` | нет | Clinic[] | Массив клиник |
| `services` | нет | Service[] | Массив услуг |
| `offers` | нет | Offer[] | Массив офферов |

## Использование

```php
use ST_system\Schemas\Yandex\MedicalFeed;

$feed = (new MedicalFeed())->fill([
    'name'    => 'Клиника Здоровье',
    'url'     => 'https://clinic.ru',
    'company' => 'ООО «Клиника Здоровье»',
    'email'   => 'info@clinic.ru',
    'doctors' => [
        [
            'id'   => 'doc-1',
            'name' => 'Иванов Иван Иванович',
            'url'  => 'https://clinic.ru/doctors/ivanov',
        ],
    ],
    'clinics' => [
        [
            'id'   => 'clinic-1',
            'name' => 'Клиника Здоровье на Ленина',
            'url'  => 'https://clinic.ru/clinics/lenina',
        ],
    ],
    'services' => [
        [
            'id'   => 'svc-1',
            'name' => 'Консультация терапевта',
        ],
    ],
    'offers' => [
        [
            'id'         => 'offer-1',
            'url'        => 'https://clinic.ru/offers/therapist',
            'service_id' => 'svc-1',
            'clinic_id'  => 'clinic-1',
            'doctor_id'  => 'doc-1',
            'speciality' => 'терапевт',
        ],
    ],
]);

header('Content-Type: application/xml; charset=UTF-8');
echo $feed->print();
// <?xml version="1.0" encoding="UTF-8"?><shop version="2.0" date="...">...</shop>
```
