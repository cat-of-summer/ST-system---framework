# MedicalProcedure.php

`ST_system\Schemas\SchemaOrg\MedicalProcedure` — Schema.org разметка медицинской процедуры. Выводит `<script type="application/ld+json">` с типом `MedicalProcedure`.

## Поля

| Поле | Обяз. | Тип | Описание |
|------|-------|-----|----------|
| `name` | да | string | Название процедуры |
| `description` | нет | string | Описание процедуры |
| `procedure_type` | нет | string | Тип процедуры (`'SurgicalProcedure'`, `'PhysicalExam'` и др.) |
| `body_location` | нет | string | Область тела (`'брюшная полость'`) |
| `preparation` | нет | string | Подготовка к процедуре |
| `status` | нет | string | Статус (`'ActiveActionStatus'`) |
| `indication` | нет | mixed | Показания (строка или массив строк) |
| `contraindication` | нет | mixed | Противопоказания (строка или массив строк) |
| `expected_prognosis` | нет | string | Ожидаемый прогноз |
| `followup` | нет | string | Последующий уход |
| `how_performed` | нет | string | Как выполняется |
| `url` | нет | url | URL страницы процедуры |
| `image` | нет | url | URL изображения |

## Использование

```php
use ST_system\Schemas\SchemaOrg\MedicalProcedure;

$procedure = (new MedicalProcedure())->fill([
    'name'           => 'УЗИ брюшной полости',
    'description'    => 'Ультразвуковое исследование органов брюшной полости',
    'procedure_type' => 'DiagnosticProcedure',
    'body_location'  => 'Живот',
    'preparation'    => 'Натощак 4-6 часов',
    'url'            => 'https://clinic.ru/services/uzi',
    'indication'     => ['Боль в животе', 'Подозрение на желчнокаменную болезнь'],
]);

echo $procedure->print();
// <script type="application/ld+json">{"@context":"https://schema.org","@type":"MedicalProcedure",...}</script>
```
