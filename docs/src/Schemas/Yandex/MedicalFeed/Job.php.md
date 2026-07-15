<!-- DOCGEN:START -->
# Job.php
<!-- DOCGEN:END -->

`ST_system\Schemas\Yandex\MedicalFeed\Job` — запись о месте работы/опыте врача, используется полем `job` схемы `Doctor`. Наследует `DefaultSchema`.

## Поля

- **`organization`** (обязательное) — место работы.
- **`period_years`** (опционально) — строка, период работы (например «2015–2020»).
- **`position`** (опционально) — должность.

## Вывод

```xml
<job>
  <organization>...</organization>
  <period_years>...</period_years>
  <position>...</position>
</job>
```

## Пример

```php
use ST_system\Schemas\Yandex\MedicalFeed\Job;

$job = Job::create()->fill([
    'organization' => 'Клиника "Здоровье"',
    'position'     => 'Врач-кардиолог',
    'period_years' => '2015–наст. время',
]);
```

Обычно создаётся автоматически внутри `Doctor::fill(['job' => [...]])`.
