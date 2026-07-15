<!-- DOCGEN:START -->
# Service

## Файлы

- [Offer.php](Offer.php.md)
- [OfferCatalog.php](OfferCatalog.php.md)
- [PostalAddress.php](PostalAddress.php.md)
- [Provider.php](Provider.php.md)

<!-- DOCGEN:END -->

Вложенные под-схемы родительской схемы `Service` (`../Service.php`):

- **`Provider`** — организация/лицо, оказывающее услугу (поле `Service::provider`); сама ссылается на `PostalAddress` через поле `address`.
- **`Offer`** — одно предложение/цена (поле `Service::offers`).
- **`OfferCatalog`** — каталог из нескольких предложений (поле `Service::has_offer_catalog`) — в отличие от `Offer`, элементы каталога описываются обычными массивами, а не вложенными объектами схемы.
- **`PostalAddress`** — почтовый адрес (поле `Provider::address`).

Все создаются автоматически при `Service::fill([...])` через `@ref`-резолв, вручную конструировать их не обязательно.
