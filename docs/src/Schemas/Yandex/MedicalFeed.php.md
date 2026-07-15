<!-- DOCGEN:START -->
# MedicalFeed.php
<!-- DOCGEN:END -->

`ST_system\Schemas\Yandex\MedicalFeed` — корневой документ медицинского XML-фида Яндекса (справочник врачей/клиник, обычно для Яндекс.Услуг или аналогичного каталога). Наследует `Schemas\DefaultSchema`. Печатает не JSON-LD, а полноценный XML-документ, собирая в себя списки вложенных схем из поддиректории `MedicalFeed\`.

## Поля

- **`date`** (опционально) — формат `Y-m-d H:i`; по умолчанию текущее время (`default:` + `date('Y-m-d H:i')`, вычисляется в момент регистрации поля).
- **`name`** (обязательное) — название магазина/каталога.
- **`company`** (опционально) — название компании.
- **`url`** (обязательное) — ссылка на сайт.
- **`picture`** (опционально) — логотип.
- **`email`** (опционально).
- **`doctors`** (опционально) — массив вложенных `MedicalFeed\Doctor` (`arrayOf('doctor')`).
- **`clinics`** (опционально) — массив вложенных `MedicalFeed\Clinic`.
- **`services`** (опционально) — массив вложенных `MedicalFeed\Service`.
- **`offers`** (опционально) — массив вложенных `MedicalFeed\Offer`.

## Вывод

`print()` строит XML вручную (не через `toArray()`):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<shop version="2.0" date="...">
  <name>...</name>
  <company>...</company>
  <url>...</url>
  <picture>...</picture>
  <email>...</email>
  <doctors>...каждый Doctor::print()...</doctors>
  <clinics>...каждый Clinic::print()...</clinics>
  <services>...каждый Service::print()...</services>
  <offers>...каждый Offer::print()...</offers>
</shop>
```

Опциональные элементы (`company`/`picture`/`email`, а также сами секции `doctors`/`clinics`/`services`/`offers`) появляются только если поле заполнено.

## Пример

```php
use ST_system\Schemas\Yandex\MedicalFeed;

$feed = MedicalFeed::create()->fill([
    'name' => 'Клиника "Здоровье"',
    'url'  => 'https://example.com',
    'clinics' => [
        ['id' => 'c1', 'name' => 'Главный корпус', 'url' => 'https://example.com/main'],
    ],
    'doctors' => [
        ['id' => 'd1', 'name' => 'Иванов Иван Иванович', 'url' => 'https://example.com/doctors/1'],
    ],
]);

header('Content-Type: application/xml; charset=utf-8');
echo $feed->print();
```

Каждый элемент `doctors`/`clinics`/`services`/`offers` автоматически коэрсится в соответствующую вложенную схему из `MedicalFeed\` — см. `MedicalFeed/README.md` за полной картой связей между всеми 10 схемами фида.
