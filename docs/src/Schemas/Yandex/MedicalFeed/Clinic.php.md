# Clinic.php

`ST_system\Schemas\Yandex\MedicalFeed\Clinic` — XML-элемент `<clinic>` для Яндекс.Здоровье. Используется через поле `clinics` схемы `MedicalFeed`.

## Поля

| Поле | Обяз. | Тип | Описание |
|------|-------|-----|----------|
| `id` | да | string | Уникальный идентификатор клиники |
| `name` | да | string | Название клиники |
| `url` | да | url | URL страницы клиники |
| `internal_id` | нет | string | Внутренний ID (если нет — используется `id`) |
| `city` | нет | string | Город |
| `address` | нет | string | Полный адрес |
| `phone` | нет | string | Телефон |
| `email` | нет | string | Email |
| `picture` | нет | url | URL изображения клиники |
| `company_id` | нет | string | ID головной организации |

## Использование

```php
use ST_system\Schemas\Yandex\MedicalFeed;

$feed = (new MedicalFeed())->fill([
    'name' => 'Сеть клиник Здоровье',
    'url'  => 'https://clinic.ru',
    'clinics' => [
        [
            'id'      => 'clinic-1',
            'name'    => 'Клиника Здоровье на Ленина',
            'url'     => 'https://clinic.ru/clinics/lenina',
            'city'    => 'Москва',
            'address' => 'ул. Ленина, 10',
            'phone'   => '+7 495 000-00-00',
            'email'   => 'lenina@clinic.ru',
            'picture' => 'https://clinic.ru/img/clinic-lenina.jpg',
        ],
    ],
]);

echo $feed->print();
```
