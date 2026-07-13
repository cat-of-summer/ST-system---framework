# DefaultParser.php

Парсер HTML/XML-страниц поверх [`IntegrationDriver`](../../IntegrationDriver.php.md). Механизм
загрузки и извлечения полностью реализован через [`WebClient`](../../../HTTP/WebClient.php.md)
и `Storage\Resource::extract()`: шаблонный URL + декартово размножение параметров + выборка по
xpath-схеме. Прежняя загрузка через `Storage\File` заменена пачечным (`WebClient::group()`)
скачиванием с повторами.

## Конфигурация (`getDefaultConfig`)

| Ключ | По умолчанию | Назначение |
|------|--------------|------------|
| `headers` | браузерный набор (UA Chrome, `accept`, `sec-*`, …) | Заголовки запросов. |
| `follow_redirects` | `true` | Следовать редиректам. |
| `delay` | `1000` | Пауза между окнами `batch`, мс (вежливость к хосту). |
| `batch` | `1` | Размер окна параллельных запросов (`1` — последовательно с паузой). |
| `requeue` | `3` | Повторов транзиентного сбоя на запрос. |

Кеш загрузок берётся из конфига `Storage\File` (`File::config('cache.*')`), `verify` — по
схеме URL (`https` → проверяем, `http` → нет).

## Создание

Схема и шаблон задаются либо аргументами конструктора, либо переопределением методов-хуков в
наследнике:

```php
// вариант 1: аргументами
$parser = DefaultParser::create([
    'template' => 'https://site.tld/doctor/{id}',
    'schema'   => [
        'name'    => '//h1',                                   // строка-селектор
        'reviews' => ['@xpath' => '//div[@class="review"]',    // с настройками
                      '@extract' => ['text' => './/p']],
    ],
]);

// вариант 2: наследник переопределяет хуки
protected function getTemplate(): string   { return 'https://site.tld/doctor/{id}'; }
protected function getSchema(): array      { return [ /* ... */ ]; }
protected function getEntrypoint(): string { return ''; } // фиксированный URL вместо шаблона
```

Схема — контракт `Storage\Mimes\Traits\Extractable::extract()` (селектор-строка либо
`['@xpath' => …, '@extract' => callable|схема, '@array' => bool]`).

## `fetch($input = null): array`

Принимает: URL-строку, ассоц-массив параметров, либо список из них. Ассоц-массивы, у которых
значения — массивы, **декартово размножаются** в отдельные запросы. Все URL резолвятся заранее
и качаются пачкой (`fetchAll` → `WebClient::group()`), затем по каждому — извлечение по схеме.

Результат — список записей в порядке входа:

```php
[ ['input' => ['id' => '7', 'url' => '…'], 'data' => [ /* извлечённое по схеме */ ]], … ]
```

На реальном транспортном сбое (нет тела / `curl errno`) — исключение; HTTP 4xx/5xx с телом
извлекаются как есть (один плохой URL не рушит пачку).

## События (в дополнение к `IntegrationDriver`)

| Событие | Сигнатура | Когда |
|---------|-----------|-------|
| `before_fetch` | `($input)` | В начале `fetch()`. |
| `before_fetch_one` | `($expanded)` | Перед резолвом URL каждого размноженного запроса. |
| `after_fetch_one` | `(&$one)` | После сборки одной записи `['input','data']` (правка/переформатирование). |
| `after_fetch` | `(&$results)` | После сборки всех записей. |
| `after_redirect` | `($input, $url, $effective, &$overrides)` | При расхождении `effective_url` с запрошенным (шаблонный режим). Слушатель может записать канонизацию в `$overrides` → `paramOverrides`. |

## Наследники

`Prodoctorov\DoctorDetailParser`, `Prodoctorov\DoctorsReviewsParser` — переопределяют
`getSchema()`/`getTemplate()` и хукают `before_fetch`/`after_fetch_one`/`after_fetch` для
формы «одиночный вход vs список».
