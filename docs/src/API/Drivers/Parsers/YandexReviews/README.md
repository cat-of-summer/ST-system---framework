<!-- DOCGEN:START -->
# YandexReviews

## Файлы

- [OrganizationReviewsParser.php](OrganizationReviewsParser.php.md)

<!-- DOCGEN:END -->

## Описание

Парсеры отзывов **Яндекс Карт**. Наследуют `Parsers\DefaultParser` и переиспользуют его конвейер загрузки.

- **OrganizationReviewsParser** - отзывы об организации (`https://yandex.ru/maps/org/{slug}/{organization_id}/reviews/`): сводка (название, адрес, рейтинг, число оценок и отзывов), аспекты и теги, список отзывов с ответами организации. Основной источник - внутренний JSON API с токеном, запасные - `state-view` страницы и DOM. Работает только с одной организацией за вызов.

`delay` увеличен до 3000 мс, чтобы не упираться в ограничение частоты запросов Яндекса (при превышении сервер отвечает `429 limited`).
