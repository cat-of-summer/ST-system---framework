# medical-procedure.php

> Документация сгенерирована AI-агентом на основе исходного кода.

## 1. Концепция

Микроразметка [schema.org/MedicalProcedure](https://schema.org/MedicalProcedure) в JSON-LD. Зарегистрирована в скопе `schema`.

```php
require_once 'medical-procedure.php';

$markup = Schema::create('schema.medical-procedure')->fill([
    'name'             => 'Липофилинг лица',
    'description'      => 'Трансплантация собственной жировой ткани.',
    'procedure_type'   => 'SurgicalProcedure',
    'body_location'    => 'Лицо',
    'indication'       => ['Глубокие носогубные складки'],
    'contraindication' => ['Сахарный диабет'],
]);
echo $markup->print(); // JSON-LD <script type="application/ld+json">...</script>
```

## 2. Поля

`name` (req), `description`, `procedure_type`, `body_location`, `preparation`, `status`, `indication` (массив), `contraindication` (массив), `expected_prognosis`, `followup`, `how_performed`, `url`, `image`.