<!-- DOCGEN:START -->
# OpenGraph

## Файлы

- [Meta.php](Meta.php.md)

<!-- DOCGEN:END -->

Разметка [Open Graph](https://ogp.me/) и [Twitter Card](https://developer.x.com/en/docs/twitter-for-websites/cards/overview/abouts-cards) —
то, из чего соцсети и мессенджеры собирают превью ссылки. Это не структурированные данные для
поисковой выдачи: формат не JSON-LD, а набор `<meta>`-тегов в `<head>`.

Пространство лежит рядом с `SchemaOrg/` и `Yandex/` по той же причине, что и они: единый
движок `DefaultSchema` даёт объявление полей, валидацию и рендер, а разница только в формате
вывода. На сегодня здесь одна схема — `Meta`.
