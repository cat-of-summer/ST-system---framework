<!-- DOCGEN:START -->
# Price.php
<!-- DOCGEN:END -->

`ST_system\Schemas\Yandex\MedicalFeed\Price` — цена услуги с опциональными скидками и списком условий бесплатного приёма, используется полем `price` схемы `Offer`. Наследует `DefaultSchema`.

## Поля

- **`base_price`** (обязательное) — float, базовая цена.
- **`currency`** (обязательное) — строка (например `RUB`).
- **`discounts`** (опционально) — массив объектов `{name: string, amount: float}`, валидируется inline-схемой `Rule::object(['name' => 'required|string', 'amount' => 'required|float'])` (не отдельный класс `DefaultSchema`, а обычная `Rule`-схема).
- **`free_appointment`** (опционально) — массив строк (`Rule::forEach('string')`) — условия, при которых приём бесплатный.

## Вывод

```xml
<price>
  <base_price>...</base_price>
  <currency>...</currency>
  <discount name="...">...amount...</discount>  <!-- по одному на каждую скидку -->
  <free_appointment>...</free_appointment>       <!-- по одному на каждое условие -->
</price>
```

## Пример

```php
use ST_system\Schemas\Yandex\MedicalFeed\Price;

$price = Price::create()->fill([
    'base_price' => 3000,
    'currency'   => 'RUB',
    'discounts'  => [
        ['name' => 'Пенсионерам', 'amount' => 10],
    ],
    'free_appointment' => ['Дети до 3 лет'],
]);
```

Обычно создаётся автоматически внутри `Offer::fill(['price' => [...]])`.
