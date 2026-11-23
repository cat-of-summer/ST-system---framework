<!-- DOCGEN:START -->
# OrganizationReviewsParser.php
<!-- DOCGEN:END -->

`final class OrganizationReviewsParser extends DefaultParser` (`namespace ST_system\API\Drivers\Parsers\YandexReviews`) - парсер отзывов об организации на **Яндекс Картах** (`https://yandex.ru/maps/org/{slug}/{organization_id}/reviews/?page={page}`). Наследует конвейер `DefaultParser`, задаёт шаблон URL, схему и сборку результата.

## Вход

`fetch()` принимает один ассоциативный массив (список и пакетные значения параметров не поддерживаются):

| Ключ | Обязателен | Описание |
|------|------------|----------|
| `slug` | да | Транслит-имя организации в URL (`topgan`) |
| `organization_id` | да | Числовой id организации (`102574933749`) |
| `page` | нет | Номер страницы (по 50 отзывов). Без него собираются все страницы |
| `ranking` | нет | `by_relevance_org` (по умолчанию), `by_time`, `by_rating_asc`, `by_rating_desc` |

Параметры страницы `ll` и `z` (центр и масштаб карты, их добавляет редирект Яндекса) на данные не влияют и не нужны. Некорректный вход даёт `\InvalidArgumentException`.

## Откуда берутся данные

Яндекс отдаёт сайту отзывы через `GET /maps/api/business/fetchReviews`. Парсер идёт по цепочке источников и пишет выбранный в `source`:

1. **`api`** - HTML первой страницы даёт токены (`csrfToken`, `sessionId`, `reqId` из `state-view`) и cookie `i` (из заголовков ответа, событие `after_response`). С ними запрашиваются остальные страницы и любая сортировка, кроме `by_relevance_org`. Запрос подписывается параметром `s`: DJB2-xor (`h = 5381; h = (h * 33) ^ код_символа`) от строки запроса без `s`, параметры по алфавиту. Токен привязан к cookie `i`: без неё API отвечает только новым `csrfToken`. Неудачный запрос повторяется один раз.
2. **`state`** - если API недоступен (параметр `api` = `false`, нет токена или cookie, ответ без данных), страницы докачиваются через `?page=N`; отзывы берутся из JSON `state-view`. Сортировка серверу в URL не передаётся, поэтому для `by_time`, `by_rating_asc` и `by_rating_desc` порядок выставляется локально.
3. **`dom`** - если `state-view` отсутствует или не разобрался, отзывы читаются из microdata (`itemprop="review"`). В DOM нет `id` отзыва, ответа организации, реакций и фото: эти поля пустые.

## Результат

```php
[
    'organization' => ['id', 'slug', 'title', 'address', 'rating', 'rating_count', 'reviews_count'],
    'aspects'      => [['text', 'count', 'positive', 'negative'], ...],
    'tags'         => [...],
    'ranking'      => 'by_relevance_org',
    'pages'        => ['total' => 2, 'loaded' => [1, 2]],
    'source'       => 'api',   // api | state | dom
    'reviews'      => [[
        'id', 'rating', 'date' /* ISO 8601, UTC */, 'text',
        'author'   => ['name', 'id', 'avatar', 'level'],
        'reply'    => ['text', 'date'],      // либо null
        'likes', 'dislikes', 'pinned',
        'photos'   => [['id', 'url']],
    ], ...],
]
```

`date` - время последнего изменения отзыва (`updatedTime`).

## Конфиг

| Ключ | По умолчанию | Описание |
|------|--------------|----------|
| `delay` | `3000` | Пауза между запросами, мс |
| `api` | `true` | `false` отключает JSON API: данные берутся только из HTML страниц |

Остальные ключи - как у `DefaultParser`. Конфиг задаётся до первого использования класса: `OrganizationReviewsParser::setConfig(['api' => false])`.

## Пример

```php
use ST_system\API\Drivers\Parsers\YandexReviews\OrganizationReviewsParser;

$parser = OrganizationReviewsParser::create();

$all    = $parser->fetch(['slug' => 'topgan', 'organization_id' => '102574933749']);
$newest = $parser->fetch(['slug' => 'topgan', 'organization_id' => '102574933749', 'ranking' => 'by_time']);
$page2  = $parser->fetch(['slug' => 'topgan', 'organization_id' => '102574933749', 'page' => 2]);

// $all['organization']['reviews_count'], $all['reviews'][0]['reply']['text']
```
