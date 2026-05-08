# yandex-medical-feed

> Документация сгенерирована AI-агентом на основе исходного кода.

## 1. Концепция

Схема XML-фида для [Yandex.Врачи](https://yandex.ru/dev/health/doc/dg/concept/medical-feed.html). Регистрируется через `Schema::entity('yandex-medical-feed')`. Фид содержит: клинику, врачей, услуги и офферы.

```php
require_once 'yandex-medical-feed.php';

$feed = Schema::create('yandex-medical-feed')->fill([
    'name'  => 'Клиника Здоровья',
    'url'   => 'https://example.com',
    'doctors' => [[
        'id'   => '1',
        'name' => 'Иванов Иван Иванович',
        'url'  => 'https://example.com/doctors/ivanov',
    ]],
    'clinics' => [[
        'id'      => '1',
        'name'    => 'Филиал Иванов',
        'url'     => 'https://example.com',
        'city'    => 'Москва',
        'address' => 'ул. Примерная, 1',
        'phone'   => '+7 (495) 000-00-00',
    ]],
    'services' => [['id' => '1', 'name' => 'Терапевт. приём']],
    'offers'   => [[
        'id'         => '1',
        'url'        => 'https://example.com/appointment',
        'doctor_id'  => '1',
        'clinic_id'  => '1',
        'service_id' => '1',
        'speciality' => 'терапевт',
    ]],
]);

header('Content-Type: application/xml');
echo $feed->print();
```

## 2. Фильды

**Корневые:** `name` (req), `url` (req), `date`, `company`, `picture`, `email`.

**`doctors[]`:** `id`, `name`, `url` (req), `description`, `surname`, `first_name`, `patronymic`, `experience_years`, `picture`.

**`clinics[]`:** `id`, `name`, `url` (req), `city`, `address`, `phone`.

**`services[]`:** `id`, `name` (req).

**`offers[]`:** `id`, `url` (req), `doctor_id`, `clinic_id`, `service_id`, `speciality`, `oms`, `online_schedule`, `appointment`, `children_appointment`, `adult_appointment`, `is_base_service`, `price[base_price, currency, discounts[], free_appointment]`..php
