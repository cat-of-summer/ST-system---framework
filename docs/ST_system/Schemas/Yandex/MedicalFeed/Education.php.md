# Education.php

`ST_system\Schemas\Yandex\MedicalFeed\Education` — XML-элемент `<education>` для Яндекс.Здоровье. Используется через поле `education` схемы `Doctor`.

## Поля

| Поле | Обяз. | Тип | Описание |
|------|-------|-----|----------|
| `organization` | да | string | Название учебного заведения |
| `finish_year` | нет | int | Год окончания |
| `type` | нет | string | Вид образования (`'Высшее'`, `'Ординатура'`, `'Интернатура'`) |
| `specialization` | нет | string | Специальность |

## Использование

```php
'education' => [
    [
        'organization'   => 'Первый МГМУ им. И.М. Сеченова',
        'finish_year'    => 2005,
        'type'           => 'Высшее',
        'specialization' => 'Лечебное дело',
    ],
    [
        'organization'   => 'Первый МГМУ им. И.М. Сеченова',
        'finish_year'    => 2007,
        'type'           => 'Ординатура',
        'specialization' => 'Кардиология',
    ],
],
```
