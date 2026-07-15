<!-- DOCGEN:START -->
# MedicalProcedure.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\MedicalProcedure` — схема [schema.org/MedicalProcedure](https://schema.org/MedicalProcedure): описание медицинской процедуры (что это, как проводится, показания/противопоказания, прогноз). Наследует `DefaultSchema`. Самостоятельная схема без вложенных под-схем — все поля скалярные.

## Поля

Все, кроме `name`, опциональны (`sometimes`):

- **`name`** (обязательное) — строка, название процедуры.
- **`description`** — строка, описание.
- **`procedure_type`** — тип процедуры (строка) → `procedureType`.
- **`body_location`** — часть тела → `bodyLocation`.
- **`preparation`** — подготовка к процедуре.
- **`status`** — статус (например `EventScheduled`/`EventCancelled` в терминах schema.org).
- **`indication`** / **`contraindication`** — показания/противопоказания; принимают скаляр или массив, при печати всегда приводятся к массиву (`(array)`).
- **`expected_prognosis`** → `expectedPrognosis`.
- **`followup`** — рекомендации после процедуры.
- **`how_performed`** → `howPerformed`.
- **`url`** / **`image`** — ссылки.

## Вывод

`print()` собирает JSON-LD с `@type: "MedicalProcedure"`; каждое опциональное поле добавляется в вывод только если оно было задано (snake_case поля переводятся в camelCase-ключи schema.org).

## Пример

```php
use ST_system\Schemas\SchemaOrg\MedicalProcedure;

$procedure = MedicalProcedure::create()->fill([
    'name'           => 'Гастроскопия',
    'procedure_type' => 'Diagnostic',
    'preparation'    => 'Не есть за 8 часов до процедуры',
    'contraindication' => ['Острое кровотечение ЖКТ'],
]);

echo $procedure->print();
```
