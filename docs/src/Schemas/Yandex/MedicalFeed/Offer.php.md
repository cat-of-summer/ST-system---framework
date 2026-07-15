<!-- DOCGEN:START -->
# Offer.php
<!-- DOCGEN:END -->

`ST_system\Schemas\Yandex\MedicalFeed\Offer` — предложение конкретной медицинской услуги конкретным врачом в конкретной клинике, используется полем `offers` корневой схемы `MedicalFeed`. Наследует `DefaultSchema`. Самая "нагруженная" схема Yandex-фида: регистрирует два собственных именованных правила `Rule` в `_init()` и ссылается на вложенную схему `Price`.

## Кастомные правила (`_init()`)

Регистрируются один раз (проверка `if (!Rule::get('...'))`, т.к. `_init()` вызывается лениво при первом создании класса — см. `DefaultSchema.php.md`, раздел «Ленивая инициализация»):

- **`boolToString`** — коэрсия: булево значение превращается в строку `'true'`/`'false'` (Yandex-фид требует текстовые `true`/`false` в XML, а не `1`/`0`). Применяется цепочкой `'sometimes|bool|boolToString'` — сначала `bool` коэрсит входное значение в PHP-булево, затем `boolToString` превращает его в нужную строку.
- **`speciality`** — валидация: строка должна входить в фиксированный список врачебных специальностей на русском (более 150 значений — терапевт, кардиолог, стоматолог и т.д.), заданный через `Rule::in([...])`.

## Поля

- **`id`**, **`url`**, **`service_id`**, **`clinic_id`**, **`doctor_id`** (все обязательные строки).
- **`oms`**, **`online_schedule`**, **`appointment`**, **`children_appointment`**, **`adult_appointment`**, **`house_call`**, **`telemed`**, **`is_base_service`** — опциональные булевы флаги (`'sometimes|bool|boolToString'`).
- **`price`** (опционально) — ссылка `@price`, резолвится в `MedicalFeed\Price`.
- **`speciality`** (обязательное) — строка, валидируется по списку `speciality`.

## Вывод

```xml
<offer id="...">
  <url>...</url>
  <oms>true|false</oms>
  <online_schedule>true|false</online_schedule>
  <appointment>true|false</appointment>
  ...price.print()...
  <service id="..."/>
  <clinic id="...">
    <doctor id="...">
      <speciality>...</speciality>
      <children_appointment>true|false</children_appointment>
      <adult_appointment>true|false</adult_appointment>
      <house_call>true|false</house_call>
      <telemed>true|false</telemed>
      <is_base_service>true|false</is_base_service>
    </doctor>
  </clinic>
</offer>
```

Обратите внимание: `<clinic>`/`<doctor>` здесь — не полные схемы `Clinic`/`Doctor`, а лёгкие XML-ссылки по `id`, вложенные друг в друга прямо внутри `Offer`.

## Пример

```php
use ST_system\Schemas\Yandex\MedicalFeed\Offer;

$offer = Offer::create()->fill([
    'id'         => 'offer-1',
    'url'        => 'https://example.com/offer-1',
    'service_id' => 'svc-1',
    'clinic_id'  => 'clinic-1',
    'doctor_id'  => 'doc-1',
    'speciality' => 'кардиолог',
    'oms'        => false,
    'price'      => ['base_price' => 2500, 'currency' => 'RUB'],
]);

echo $offer->print();
```
