<!-- DOCGEN:START -->
# Service.php
<!-- DOCGEN:END -->

`ST_system\Schemas\Yandex\MedicalFeed\Service` — описание медицинской услуги, используется полем `services` корневой схемы `MedicalFeed` и полем `service_id` схемы `Offer` (по ссылке-идентификатору, без вложенного объекта). Наследует `DefaultSchema`. Не путать с `SchemaOrg\Service` — это отдельная, более простая схема специально для формата Yandex-фида.

## Поля

- **`id`** (обязательное), **`internal_id`** (опционально, фоллбэк на `id` при выводе).
- **`name`** (обязательное).
- **`gov_id`** (опционально) — государственный идентификатор услуги (номенклатура Минздрава и т.п.).
- **`description`** (опционально) — обрезается `trim()` при выводе.

## Вывод

```xml
<service id="...">
  <name>...</name>
  <gov_id>...</gov_id>
  <description>...</description>
  <internal_id>...</internal_id>
</service>
```

## Пример

```php
use ST_system\Schemas\Yandex\MedicalFeed\Service;

$service = Service::create()->fill([
    'id'     => 'svc-1',
    'name'   => 'Консультация кардиолога',
    'gov_id' => 'B04.026.001',
]);
```

Обычно создаётся автоматически внутри `MedicalFeed::fill(['services' => [...]])`.
