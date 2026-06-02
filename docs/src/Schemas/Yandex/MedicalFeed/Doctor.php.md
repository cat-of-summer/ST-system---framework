# Doctor.php

`ST_system\Schemas\Yandex\MedicalFeed\Doctor` — XML-элемент `<doctor>` для Яндекс.Здоровье. Используется через поле `doctors` схемы `MedicalFeed`.

## Поля

| Поле | Обяз. | Тип | Описание |
|------|-------|-----|----------|
| `id` | да | string | Уникальный идентификатор врача |
| `name` | да | string | Полное имя врача |
| `url` | да | url | URL страницы врача на сайте |
| `internal_id` | нет | string | Внутренний ID (если нет — используется `id`) |
| `description` | нет | string | Описание врача |
| `surname` | нет | string | Фамилия |
| `first_name` | нет | string | Имя |
| `patronymic` | нет | string | Отчество |
| `experience_years` | нет | int | Стаж в годах |
| `career_start_date` | нет | string | Дата начала карьеры |
| `picture` | нет | url | URL фотографии |
| `degree` | нет | string | Учёная степень |
| `rank` | нет | string | Звание |
| `category` | нет | string | Квалификационная категория |
| `education` | нет | Education[] | Образование |
| `job` | нет | Job[] | Места работы |
| `certificate` | нет | Certificate[] | Сертификаты |
| `reviews_total_count` | нет | int | Общее число отзывов |
| `review` | нет | Review[] | Отзывы о враче |

## Использование

```php
use ST_system\Schemas\Yandex\MedicalFeed;

$feed = (new MedicalFeed())->fill([
    'name' => 'Клиника Здоровье',
    'url'  => 'https://clinic.ru',
    'doctors' => [
        [
            'id'               => 'doc-42',
            'name'             => 'Иванов Иван Иванович',
            'url'              => 'https://clinic.ru/doctors/ivanov',
            'surname'          => 'Иванов',
            'first_name'       => 'Иван',
            'patronymic'       => 'Иванович',
            'experience_years' => 15,
            'picture'          => 'https://clinic.ru/img/ivanov.jpg',
            'degree'           => 'Кандидат медицинских наук',
            'education' => [
                ['organization' => 'Первый МГМУ им. Сеченова', 'finish_year' => 2005, 'specialization' => 'Терапия'],
            ],
            'certificate' => [
                ['organization' => 'МЗ РФ', 'name' => 'Терапия', 'finish_year' => 2020],
            ],
        ],
    ],
]);

echo $feed->print();
```
