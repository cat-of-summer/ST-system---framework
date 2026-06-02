# Certificate.php

`ST_system\Schemas\Yandex\MedicalFeed\Certificate` — XML-элемент `<certificate>` для Яндекс.Здоровье. Используется через поле `certificate` схемы `Doctor`.

## Поля

| Поле | Обяз. | Тип | Описание |
|------|-------|-----|----------|
| `organization` | да | string | Организация, выдавшая сертификат |
| `name` | да | string | Название специальности/сертификата |
| `finish_year` | нет | int | Год окончания действия |

## Использование

```php
'certificate' => [
    [
        'organization' => 'Министерство здравоохранения РФ',
        'name'         => 'Кардиология',
        'finish_year'  => 2025,
    ],
    [
        'organization' => 'НМП «Национальная медицинская палата»',
        'name'         => 'Кардиология',
        'finish_year'  => 2026,
    ],
],
```
