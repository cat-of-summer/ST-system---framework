<!-- DOCGEN:START -->
# Provider.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\Service\Provider` — вложенная схема, описывающая организацию/лицо, оказывающее услугу ([schema.org/Organization](https://schema.org/Organization) или [Person](https://schema.org/Person) — тип настраивается полем `type`). Используется полем `provider` схемы `Service`. Наследует `DefaultSchema`. Сама содержит вложенную ссылку на `PostalAddress`.

## Поля

- **`type`** (опционально) — `@type` вывода, по умолчанию `Organization`.
- **`name`** (обязательное).
- **`url`**, **`telephone`** (опционально).
- **`address`** (опционально) — ссылка `@postal-address`, резолвится в `Service\PostalAddress`.

## Вывод (`toArray()`)

`{"@type": <type ?? "Organization">, "name": ...}` плюс `url`/`telephone`, если заданы, и `address` (встраивается через `->toArray()` `PostalAddress`), если задан.

## Пример

```php
use ST_system\Schemas\SchemaOrg\Service\Provider;

$provider = Provider::create()->fill([
    'name'      => 'Клиника "Здоровье"',
    'telephone' => '+7 900 000-00-00',
    'address'   => ['address_locality' => 'Москва', 'street_address' => 'ул. Примерная, 1'],
]);
```

Вложенный `address` можно передать обычным ассоциативным массивом — он автоматически коэрсится в `PostalAddress`. Обычно создаётся не напрямую, а внутри `Service::fill(['provider' => [...]])`.
