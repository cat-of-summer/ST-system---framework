# OfferCatalog.php

`ST_system\Schemas\SchemaOrg\Service\OfferCatalog` — Schema.org каталог предложений услуги. Используется внутри `Service` через поле `has_offer_catalog`. Экспортирует данные через `toArray()`.

## Поля

| Поле | Обяз. | Тип | Описание |
|------|-------|-----|----------|
| `name` | да | string | Название каталога |
| `items` | нет | array | Массив элементов каталога (каждый: `type`, `name`, `url`) |

### Структура элемента `items`

| Ключ | Тип | Описание |
|------|-----|----------|
| `type` | string | Schema.org тип (`'Service'`). Умолч: `'Service'` |
| `name` | string | Название услуги |
| `url` | string | URL услуги |

## Использование

```php
use ST_system\Schemas\SchemaOrg\Service;

$service = (new Service())->fill([
    'service_type'      => 'MedicalTherapy',
    'name'              => 'Диагностика',
    'has_offer_catalog' => [
        'name'  => 'Диагностические услуги',
        'items' => [
            ['name' => 'УЗИ',  'url' => 'https://clinic.ru/uzi'],
            ['name' => 'МРТ',  'url' => 'https://clinic.ru/mrt', 'type' => 'MedicalProcedure'],
        ],
    ],
]);

echo $service->print();
```
