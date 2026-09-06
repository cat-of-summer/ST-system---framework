<!-- DOCGEN:START -->
# Meta.php
<!-- DOCGEN:END -->

`ST_system\Schemas\OpenGraph\Meta` — набор meta-тегов Open Graph и Twitter Card для одной
страницы. В отличие от схем `SchemaOrg` печатает не JSON-LD, а строки вида
`<meta property="og:…">` и `<meta name="twitter:…">`.

## Поля

| Поле | Тег |
|---|---|
| `type` (по умолчанию `website`) | `og:type` — `website`, `article`, `product` |
| `title` (обязательное) | `og:title` |
| `description` | `og:description` |
| `url` | `og:url` — канонический адрес страницы |
| `image` | `og:image` — абсолютный URL **растрового** изображения |
| `image_width`, `image_height`, `image_alt` | `og:image:width`, `og:image:height`, `og:image:alt` |
| `site_name` | `og:site_name` |
| `locale` | `og:locale` — `ru_RU`, `en_US` |
| `article_published_time`, `article_modified_time` | `article:published_time`, `article:modified_time` |
| `twitter_card` | `twitter:card` |
| `twitter_title`, `twitter_description`, `twitter_image` | одноимённые теги |
| `skip` | не тег — список имён тегов, которые печатать не нужно |

`og:image` должен быть растром (JPG/PNG): SVG в превью не рендерится ни в одной соцсети.
Рекомендованный размер — 1200×630.

## Поведение

- Незаполненные поля не печатаются — пустых `content=""` в выводе не будет.
- `twitter_title`, `twitter_description`, `twitter_image` по умолчанию наследуют
  `title`, `description`, `image`, поэтому дублировать их не нужно.
- Если `twitter_card` не задан, но картинка есть, подставляется `summary_large_image`.
- `skip` нужен, когда часть тегов уже проставлена на странице вручную (например, заведена
  в админке для конкретного URL) — шаблонный вывод не должен их дублировать.
- Значения экранируются `htmlspecialchars(..., ENT_QUOTES)`.

## Пример использования

```php
use ST_system\Schemas\OpenGraph\Meta;

echo Meta::create()->fill([
    'type'        => 'product',
    'title'       => 'Солнечный модуль HVL-445/HJT',
    'description' => 'Гетероструктурный модуль мощностью 445 Вт',
    'url'         => 'https://example.com/catalog/solnechnye-moduli/hvl-445hjt/',
    'image'       => 'https://example.com/loaded/catalog/goods/hvl-445.jpg',
    'site_name'   => 'Хевел',
    'locale'      => 'ru_RU',
    'skip'        => ['og:description'],
])->print();
```

`toArray()` возвращает не плоский список, а два массива — `['og' => [...], 'twitter' => [...]]`
с уже отфильтрованными тегами: пригодится, если теги надо не напечатать, а отдать шаблону.
