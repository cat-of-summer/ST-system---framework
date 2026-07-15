<!-- DOCGEN:START -->
# DoctorsReviewsParser.php
<!-- DOCGEN:END -->

`final class DoctorsReviewsParser extends DefaultParser` (`namespace ST_system\API\Drivers\Parsers\Prodoctorov`) — парсер страницы отзывов о враче на **Prodoctorov.ru** (`https://prodoctorov.ru/kaliningrad/vrach/{vrach_id}/otzivi/`). Как и `DoctorDetailParser`, наследует конвейер `DefaultParser` и задаёт только шаблон, схему и постобработку результата.

## Как это устроено

- `getDefaultConfig()` увеличивает `delay` до `5000` мс.
- `__init()` вызывает `parent::__init()` и добавляет:
  - `before_fetch` — бросает `\InvalidArgumentException`, если `vrach_id` передан как массив (в отличие от `DoctorDetailParser`, пакетные запросы не поддерживаются — всегда одна страница за вызов);
  - `after_fetch_one` — разворачивает элемент результата из `['data' => ...]` в просто `data`;
  - `after_fetch` — всегда схлопывает результат в один ассоциативный массив (`$results[0] ?? []`).
- `getSchema()` извлекает сводку по врачу (`name`, `avatar` с достраиванием абсолютного URL, `price`, `description`, `updated` — дата, нормализованная в формат `Y-m-d`) и список `reviews` — по одному элементу на отзыв пациента: `author`, `date` (`datePublished`), `rating`, `direction` (направление приёма), `story`/`liked`/`disliked` (тексты отзыва с очисткой от неразрывных пробелов, zero-width-символов и многоточий-заглушек `[...]`), `reply` (ответ клиники: `date`+`text`), `clinicName`, `clinicAddress`.

## Публичные методы

Собственных публичных методов не добавляет — используется как обычный `DefaultParser`: `fetch()` и `purge()`.

## Пример

```php
use ST_system\API\Drivers\Parsers\Prodoctorov\DoctorsReviewsParser;

$parser = DoctorsReviewsParser::create();

$page = $parser->fetch(['vrach_id' => '123456']);
// $page['name'], $page['price'], $page['reviews'] — список отзывов
```
