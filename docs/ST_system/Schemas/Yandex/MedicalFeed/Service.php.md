# Service.php

`ST_system\Schemas\Yandex\MedicalFeed\Service` — XML-элемент `<service>` для Яндекс.Здоровье. Используется через поле `services` схемы `MedicalFeed`.

## Поля

| Поле | Обяз. | Тип | Описание |
|------|-------|-----|----------|
| `id` | да | string | Уникальный идентификатор услуги |
| `name` | да | string | Название услуги |
| `internal_id` | нет | string | Внутренний ID (если нет — используется `id`) |
| `gov_id` | нет | string | Государственный код услуги (по номенклатуре МЗ РФ) |
| `description` | нет | string | Описание услуги |

## Использование

```php
use ST_system\Schemas\Yandex\MedicalFeed;

$feed = (new MedicalFeed())->fill([
    'name' => 'Клиника Здоровье',
    'url'  => 'https://clinic.ru',
    'services' => [
        [
            'id'          => 'svc-1',
            'name'        => 'Консультация терапевта',
            'gov_id'      => 'A01.31.001',
            'description' => 'Первичный приём врача-терапевта',
        ],
        [
            'id'   => 'svc-2',
            'name' => 'УЗИ брюшной полости',
        ],
    ],
]);

echo $feed->print();
```
