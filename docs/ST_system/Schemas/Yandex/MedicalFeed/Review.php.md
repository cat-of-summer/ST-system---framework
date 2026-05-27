# Review.php

`ST_system\Schemas\Yandex\MedicalFeed\Review` — XML-элемент `<review>` для Яндекс.Здоровье. Используется через поле `review` схемы `Doctor`.

## Поля

| Поле | Обяз. | Тип | Описание |
|------|-------|-----|----------|
| `date` | да | string | Дата отзыва (например `'2024-01-15'`) |
| `author` | да | string | Имя автора |
| `comment` | да | string | Текст отзыва |
| `checked` | нет | bool | Отзыв проверен модератором |
| `used_in_rating` | нет | bool | Учитывается в рейтинге |
| `author_id` | нет | string | ID автора |
| `author_picture` | нет | url | URL аватара автора |
| `url` | нет | url | URL отзыва |
| `grade` | нет | float | Оценка (например `4.5`) |
| `positive` | нет | string | Достоинства |
| `negative` | нет | string | Недостатки |
| `response` | нет | string | Ответ клиники/врача |

> `checked` и `used_in_rating` конвертируются в строки `'true'`/`'false'` автоматически.

## Использование

```php
use ST_system\Schemas\Yandex\MedicalFeed;

$feed = (new MedicalFeed())->fill([
    'name'    => 'Клиника Здоровье',
    'url'     => 'https://clinic.ru',
    'doctors' => [
        [
            'id'     => 'doc-1',
            'name'   => 'Иванов И.И.',
            'url'    => 'https://clinic.ru/doctors/ivanov',
            'review' => [
                [
                    'date'           => '2024-03-10',
                    'author'         => 'Мария П.',
                    'comment'        => 'Отличный врач, всё объяснил доступно.',
                    'grade'          => 5.0,
                    'positive'       => 'Внимательность, профессионализм',
                    'checked'        => true,
                    'used_in_rating' => true,
                ],
            ],
        ],
    ],
]);
```
