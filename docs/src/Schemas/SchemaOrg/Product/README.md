<!-- DOCGEN:START -->
# Product

## Файлы

- [Offer.php](Offer.php.md)

<!-- DOCGEN:END -->

`Offer` — вложенная схема предложения (цена, валюта, наличие, продавец), используется полем
`offers` родительской схемы `Product` (`../Product.php`). Создаётся автоматически при
`Product::fill(['offers' => [...]])`.

Не путать с `../Service/Offer.php`: тот обслуживает `Service` и не умеет `seller`.
