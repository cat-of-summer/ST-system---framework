<!-- DOCGEN:START -->
# Service.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\Service` — схема [schema.org/Service](https://schema.org/Service): описание оказываемой услуги (медицинской, коммерческой и т.п.), с опциональной вложенной информацией о поставщике (`Provider`), предложении (`Offer`) и каталоге предложений (`OfferCatalog`). Наследует `DefaultSchema`. Это самый "составной" из всех SchemaOrg-типов проекта — использует `@ref` для трёх разных вложенных под-схем.

## Поля

- **`service_type`** (обязательное) → `serviceType`.
- **`name`**, **`description`**, **`url`**, **`image`**, **`area_served`** (→ `areaServed`) — опциональные скаляры.
- **`provider`** (опционально) — ссылка `@provider`, резолвится в `Service\Provider`.
- **`offers`** (опционально) — ссылка `@offer`, резолвится в `Service\Offer`.
- **`has_offer_catalog`** (опционально) → `hasOfferCatalog`, ссылка `@offer-catalog`, резолвится в `Service\OfferCatalog`.

Все три `@ref`-поля резолвятся через `DefaultSchema::resolveRef()` по правилу «вложенный namespace текущей схемы» — то есть `@provider` внутри `Service` ищет именно `SchemaOrg\Service\Provider`, а не какой-либо другой класс `Provider` в проекте.

## Вывод

`print()` собирает JSON-LD с `@type: "Service"`; `provider`/`offers`/`has_offer_catalog`, если заданы, встраиваются через `->toArray()` соответствующей вложенной схемы.

## Пример

```php
use ST_system\Schemas\SchemaOrg\Service;

$service = Service::create()->fill([
    'service_type' => 'MedicalBusiness',
    'name'         => 'Приём кардиолога',
    'provider'     => [
        'name'      => 'Клиника "Здоровье"',
        'telephone' => '+7 900 000-00-00',
        'address'   => ['address_locality' => 'Москва', 'street_address' => 'ул. Примерная, 1'],
    ],
    'offers' => [
        'price'          => '2500',
        'price_currency' => 'RUB',
    ],
]);

echo $service->print();
```

Вложенные ассоциативные массивы (`provider`, `offers`, `provider.address`) автоматически коэрсятся в экземпляры соответствующих схем — писать `Provider::create()->fill(...)` вручную не нужно.
