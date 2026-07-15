<!-- DOCGEN:START -->
# Parsers

## Папки

- [Prodoctorov](Prodoctorov/)

## Файлы

- [DefaultParser.php](DefaultParser.php.md)

<!-- DOCGEN:END -->

## Описание

Драйверы для HTML-скрапинга/парсинга внешних сайтов. Базовый класс `DefaultParser` (расширяет `API\IntegrationDriver`, сам не `final` — предназначен для наследования) реализует общий конвейер: резолвинг URL по шаблону/entrypoint, пакетная загрузка страниц через `WebClient::group()` (окна `batch`/`delay`, повторы `requeue`, файловый кэш), обработка редиректов и извлечение данных по декларативной XPath-схеме (`getSchema()`).

Конкретные парсеры под каждый сайт лежат в подпапках (см. [Prodoctorov](Prodoctorov/)) и переопределяют только `getEntrypoint()`/`getTemplate()`/`getSchema()` и постобработку результата через события `before_fetch`/`after_fetch_one`/`after_fetch`/`after_redirect`.
