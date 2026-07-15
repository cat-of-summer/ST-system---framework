<!-- DOCGEN:START -->
# Review.php
<!-- DOCGEN:END -->

`ST_system\Schemas\Yandex\MedicalFeed\Review` — отзыв пациента о враче, используется полем `review` схемы `Doctor`. Наследует `DefaultSchema`. Регистрирует правило `boolToString` в своём собственном `_init()` (та же коэрсия bool→`'true'`/`'false'`, что и в `Offer`, но зарегистрирована независимо — правила `Rule` не разделяются автоматически между схемами без общего родителя в цепочке scope).

## Поля

- **`date`** (обязательное) — строка-дата.
- **`checked`**, **`used_in_rating`** (опционально) — булевы флаги (`'sometimes|bool|boolToString'`).
- **`author`** (обязательное), **`author_id`**, **`author_picture`**, **`url`** (опционально).
- **`comment`** (обязательное) — текст отзыва.
- **`grade`** (опционально, float) — оценка.
- **`positive`**, **`negative`**, **`response`** (опционально) — плюсы/минусы/ответ клиники на отзыв.

## Вывод

```xml
<review>
  <date>...</date>
  <checked>true|false</checked>
  <used_in_rating>true|false</used_in_rating>
  <author>...</author>
  <author_id>...</author_id>
  <author_picture>...</author_picture>
  <url>...</url>
  <comment>...</comment>
  <grade>...</grade>
  <positive>...</positive>
  <negative>...</negative>
  <response>...</response>
</review>
```

## Пример

```php
use ST_system\Schemas\Yandex\MedicalFeed\Review;

$review = Review::create()->fill([
    'date'    => '2024-05-01',
    'author'  => 'Анна',
    'comment' => 'Отличный врач, всё подробно объяснил.',
    'grade'   => 5.0,
]);
```

Обычно создаётся автоматически внутри `Doctor::fill(['review' => [...]])`.
