<!-- DOCGEN:START -->
# Doctor.php
<!-- DOCGEN:END -->

`ST_system\Schemas\Yandex\MedicalFeed\Doctor` — описание врача, используется полем `doctors` корневой схемы `MedicalFeed`. Наследует `DefaultSchema`. Самая "составная" схема фида — собирает в себе списки `Education`, `Job`, `Certificate` и `Review`.

## Поля

- **`id`** (обязательное), **`internal_id`** (опционально, фоллбэк на `id` при выводе).
- **`name`**, **`url`** (обязательные).
- **`description`**, **`surname`**, **`first_name`**, **`patronymic`** — опциональные строки.
- **`experience_years`** (int), **`career_start_date`**, **`picture`**, **`degree`**, **`rank`**, **`category`** — опциональны.
- **`education`** (опционально) — массив вложенных `Education` (`arrayOf('education')`).
- **`job`** (опционально) — массив вложенных `Job`.
- **`certificate`** (опционально) — массив вложенных `Certificate`.
- **`reviews_total_count`** (опционально, int).
- **`review`** (опционально) — массив вложенных `Review`.

## Вывод

```xml
<doctor id="...">
  <name>...</name>
  <url>...</url>
  <description>...</description>   <!-- через trim() -->
  <internal_id>...</internal_id>
  <first_name>...</first_name>
  <surname>...</surname>
  <patronymic>...</patronymic>
  <experience_years>...</experience_years>
  <career_start_date>...</career_start_date>
  <picture>...</picture>
  <degree>...</degree>
  <rank>...</rank>
  <category>...</category>
  ...education.print() для каждого...
  ...job.print() для каждого...
  ...certificate.print() для каждого...
  <reviews_total_count>...</reviews_total_count>
  ...review.print() для каждого...
</doctor>
```

## Пример

```php
use ST_system\Schemas\Yandex\MedicalFeed\Doctor;

$doctor = Doctor::create()->fill([
    'id'         => 'doc-1',
    'name'       => 'Иванов Иван Иванович',
    'url'        => 'https://example.com/doctors/1',
    'first_name' => 'Иван',
    'surname'    => 'Иванов',
    'education'  => [
        ['organization' => 'Первый МГМУ им. Сеченова', 'finish_year' => 2010],
    ],
    'job' => [
        ['organization' => 'Клиника "Здоровье"', 'position' => 'Кардиолог'],
    ],
]);
```

Вложенные массивы `education`/`job`/`certificate`/`review` автоматически коэрсятся в соответствующие схемы — см. `MedicalFeed/README.md` за полной картой связей.
