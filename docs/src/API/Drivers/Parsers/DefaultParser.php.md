<!-- DOCGEN:START -->
# DefaultParser.php
<!-- DOCGEN:END -->

`class DefaultParser extends IntegrationDriver` (не `final` — предназначен для наследования, `namespace ST_system\API\Drivers\Parsers`). Базовый класс для HTML-скрапинга/парсинга страниц: схема- и шаблон-driven — конкретные парсеры (например, `Prodoctorov\DoctorDetailParser`, `Prodoctorov\DoctorsReviewsParser`) описывают, откуда брать URL (`entrypoint`/`template`) и какие данные извлекать (`schema`), а `DefaultParser` берёт на себя весь конвейер загрузки страниц через `WebClient` и извлечение данных.

## Как это устроено

Подкласс обычно переопределяет:
- `getEntrypoint(): string` — фиксированный URL (если страница одна и не параметризована);
- `getTemplate(): string` — шаблон URL с плейсхолдерами `{param}` (например, `.../vrach/{vrach_id}/`);
- `getSchema(): array` — схему извлечения данных (`@xpath`, `@array`, `@extract`, вложенные схемы — интерпретируются `Resource::extract()`).

Схема/шаблон можно также передать через конструктор (`['schema' => [...], 'template' => '...']`, валидируется `Rule::object(['schema' => 'array', 'template' => 'string'])`).

Жизненный цикл `fetch()` порождает дополнительные события (помимо базовых из `IntegrationDriver`): `before_fetch`, `before_fetch_one`, `after_fetch_one`, `after_fetch`, `after_redirect`.

`fetch($input)` принимает:
- `null` или строку URL — один запрос;
- список (`array` с числовыми ключами) — пакет независимых запросов, по одному job на элемент;
- ассоциативный массив параметров, где значение параметра само может быть массивом — тогда строится декартово произведение вариантов (каждая комбинация — отдельный job, значения подставляются в `template` по `{key}`).

Все URL пачки загружаются за один проход через `WebClient::group()` (окна `batch`, пауза `delay`, повторы транзиентных ошибок по `requeue`, кэш на файловой системе — общий конвейер, конфигурируется через `getDefaultConfig()`). При редиректе (`effective_url !== url`) для параметризованных запросов фреймворк даёт подклассу шанс запомнить каноническое значение параметра через `after_redirect` (`$overrides`) — оно подставляется в URL последующих запросов с тем же значением параметра.

Каждый результат — `['input' => ..., 'data' => ...]`, где `data` получена вызовом `$resource->extract($schema, $data)` на скачанном `Resource`. Подклассы обычно донастраивают форму итогового результата через `after_fetch_one`/`after_fetch` (например, разворачивают одиночный запрос в плоский массив вместо списка из одного элемента).

## Публичные методы

- `fetch($input = null): array` — выполнить загрузку и извлечение данных (см. форматы `$input` выше).
- `purge(): void` — сбросить накопленные `paramOverrides` (канонические значения параметров после редиректов) и очистить кэш (`purgeBase()`).

## Пример

```php
use ST_system\API\Drivers\Parsers\DefaultParser;

class MyParser extends DefaultParser {
    protected function getTemplate(): string { return 'https://example.com/{id}/'; }

    protected function getSchema(): array {
        return [
            'title' => ['@xpath' => '//h1', '@array' => false],
        ];
    }
}

$parser = MyParser::create();

$one   = $parser->fetch(['id' => 123]);            // один job
$batch = $parser->fetch(['id' => [123, 456]]);      // несколько job (декартово произведение)

$parser->purge(); // сбросить оверрайды параметров и кэш
```
