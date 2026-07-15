<!-- DOCGEN:START -->
# MedicalFeed

## Файлы

- [Certificate.php](Certificate.php.md)
- [Clinic.php](Clinic.php.md)
- [Doctor.php](Doctor.php.md)
- [Education.php](Education.php.md)
- [Job.php](Job.php.md)
- [Offer.php](Offer.php.md)
- [Price.php](Price.php.md)
- [Review.php](Review.php.md)
- [Service.php](Service.php.md)

<!-- DOCGEN:END -->

Схемы медицинского XML-фида Яндекса, все наследуют `Schemas\DefaultSchema`, корень — `Yandex\MedicalFeed` (`../MedicalFeed.php`). Связи по вложенности:

- **`MedicalFeed`** (корень) содержит списки `Doctor`, `Clinic`, `Service`, `Offer`.
- **`Doctor`** содержит списки `Education`, `Job`, `Certificate`, `Review`.
- **`Offer`** — предложение конкретной услуги конкретным врачом в конкретной клинике; ссылается на `service_id`/`clinic_id`/`doctor_id` (по строковому ID, без вложенного объекта) и на вложенную схему `Price`.
- **`Price`** — цена услуги (используется полем `price` схемы `Offer`).
- **`Certificate`**, **`Education`**, **`Job`**, **`Review`** — независимые листовые схемы, каждая используется только одним полем `Doctor`.
- **`Clinic`**, **`Service`** — независимые листовые схемы верхнего уровня, используются полями `MedicalFeed::clinics`/`MedicalFeed::services`.

`Offer` и `Review` каждая регистрирует собственное правило `boolToString` (bool → строка `'true'`/`'false'`, обязательная для XML-формата Yandex) — правила `Rule` не разделяются автоматически между разными схемами, поэтому оно объявлено независимо в `_init()` каждой из них.
