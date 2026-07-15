<!-- DOCGEN:START -->
# DoctorDetailParser.php
<!-- DOCGEN:END -->

`final class DoctorDetailParser extends DefaultParser` (`namespace ST_system\API\Drivers\Parsers\Prodoctorov`) — парсер карточки врача на **Prodoctorov.ru** (`https://prodoctorov.ru/kaliningrad/vrach/{vrach_id}/`). Наследует весь конвейер загрузки/извлечения из `DefaultParser`, задаёт только шаблон URL, схему извлечения и правила сборки результата.

## Как это устроено

- `getDefaultConfig()` увеличивает `delay` до `5000` мс (более щадящий темп запросов к сайту).
- `__init()` вызывает `parent::__init()` и добавляет:
  - `before_fetch` — определяет, был ли вход одиночным (`$isSingle`): скаляр/строка или массив с нескалярным `vrach_id` (тогда это пачка);
  - `after_fetch_one` — разворачивает элемент результата из `['data' => ...]` в просто `data`;
  - `after_fetch` — если запрос был одиночным (`$isSingle`), схлопывает список результатов в один ассоциативный массив (`$results[0]`), иначе оставляет список как есть.
- `getSchema()` описывает извлечение: `name`, `avatar` (абсолютный URL, если исходный относительный — достраивается по host из URL страницы), `specialties` (список), `experience` (число лет), `languages` (список), `treatment_profiles` (пары `percent`/`text`), `job` (место работы: `name`+`period`, через `preceding-sibling` XPath), `education` (`institution`/`year`/`specialty`/`type`), `courses` (пары `year`/`text`), `associations` (список), `documents` (список `{url, title}` из галереи документов, URL также достраивается до абсолютного).

## Публичные методы

Собственных публичных методов не добавляет — используется как обычный `DefaultParser`: `fetch()` и `purge()`.

## Пример

```php
use ST_system\API\Drivers\Parsers\Prodoctorov\DoctorDetailParser;

$parser = DoctorDetailParser::create();

$doctor  = $parser->fetch(['vrach_id' => '123456']);              // одна карточка -> ассоц. массив
$doctors = $parser->fetch(['vrach_id' => ['123456', '234567']]);   // список карточек
```
