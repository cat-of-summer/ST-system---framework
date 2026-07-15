<!-- DOCGEN:START -->
# Education.php
<!-- DOCGEN:END -->

`ST_system\Schemas\Yandex\MedicalFeed\Education` — запись об образовании врача, используется полем `education` схемы `Doctor`. Наследует `DefaultSchema`.

## Поля

- **`organization`** (обязательное) — учебное заведение.
- **`finish_year`** (опционально, int).
- **`type`** (опционально) — тип образования (например «высшее», «ординатура»).
- **`specialization`** (опционально) — специализация.

## Вывод

```xml
<education>
  <organization>...</organization>
  <finish_year>...</finish_year>
  <type>...</type>
  <specialization>...</specialization>
</education>
```

Все поля, кроме `organization`, включаются в вывод только если заданы.

## Пример

```php
use ST_system\Schemas\Yandex\MedicalFeed\Education;

$edu = Education::create()->fill([
    'organization'   => 'Первый МГМУ им. Сеченова',
    'finish_year'    => 2010,
    'specialization' => 'Кардиология',
]);
```

Обычно создаётся автоматически внутри `Doctor::fill(['education' => [...]])`.
