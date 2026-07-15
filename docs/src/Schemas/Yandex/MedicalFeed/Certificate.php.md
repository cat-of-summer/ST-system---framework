<!-- DOCGEN:START -->
# Certificate.php
<!-- DOCGEN:END -->

`ST_system\Schemas\Yandex\MedicalFeed\Certificate` — сертификат врача (например, о повышении квалификации), используется полем `certificate` схемы `Doctor`. Наследует `DefaultSchema`.

## Поля

- **`organization`** (обязательное) — организация, выдавшая сертификат.
- **`finish_year`** (опционально) — int, год получения.
- **`name`** (обязательное) — название сертификата/курса.

## Вывод

```xml
<certificate>
  <organization>...</organization>
  <finish_year>...</finish_year>
  <name>...</name>
</certificate>
```

`finish_year` попадает в вывод только если задан.

## Пример

```php
use ST_system\Schemas\Yandex\MedicalFeed\Certificate;

$cert = Certificate::create()->fill([
    'organization' => 'РМАПО',
    'finish_year'  => 2020,
    'name'         => 'Повышение квалификации по кардиологии',
]);
```

Обычно создаётся автоматически внутри `Doctor::fill(['certificate' => [...]])`.
