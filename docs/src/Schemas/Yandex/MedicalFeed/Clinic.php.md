<!-- DOCGEN:START -->
# Clinic.php
<!-- DOCGEN:END -->

`ST_system\Schemas\Yandex\MedicalFeed\Clinic` — описание клиники (филиала), используется полем `clinics` корневой схемы `MedicalFeed`. Наследует `DefaultSchema`. Самостоятельная схема без вложенных под-схем.

## Поля

- **`id`** (обязательное) — уникальный идентификатор клиники (атрибут XML-узла).
- **`internal_id`** (опционально) — внутренний ID; при выводе, если не задан, дублирует `id`.
- **`name`** (обязательное).
- **`url`** (обязательное).
- **`city`**, **`address`**, **`phone`**, **`email`**, **`picture`**, **`company_id`** — все опциональны.

## Вывод

```xml
<clinic id="...">
  <url>...</url>
  <picture>...</picture>
  <name>...</name>
  <city>...</city>
  <address>...</address>
  <email>...</email>
  <phone>...</phone>
  <internal_id>...</internal_id>
  <company_id>...</company_id>
</clinic>
```

Опциональные элементы включаются только если поле задано; `internal_id` выводится всегда (фоллбэк на `id`).

## Пример

```php
use ST_system\Schemas\Yandex\MedicalFeed\Clinic;

$clinic = Clinic::create()->fill([
    'id'      => 'clinic-1',
    'name'    => 'Клиника на Ленина',
    'url'     => 'https://example.com/clinic-1',
    'city'    => 'Москва',
    'address' => 'ул. Ленина, 10',
]);
```

Обычно создаётся автоматически внутри `MedicalFeed::fill(['clinics' => [...]])`.
