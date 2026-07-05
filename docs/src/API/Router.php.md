# Router

## 1. Концепция

`Router` — маршрутизатор URL с поддержкой приоритетов, `{param}`-захватов, строгого/нестрогого режимов сопоставления. Каждый экземпляр хранится в статическом реестре `$URL_parsers_list` (по `key` или авто-индексу). Позволяет обрабатывать несколько правил с одинаковым приоритетом разными параметрами.

```php
$router = new Router([
    'key'       => 'main',
    'url_rules' => [
        ['/catalog/', ['type' => 'catalog']],
        ['/catalog/{slug}/', ['type' => 'product'], 1], // приоритет 1
        ['/news/', ['type' => 'news']],
    ],
    'strict_mode' => false,
    'apply_once'  => true,
    'rules_handler' => function($PARSER_PARAMS, $URL_PARAMS, $PAGE_PARAMS) {
        // Обработка сработавших правил
        return ['PARSER_PARAMS' => $PARSER_PARAMS, 'URL_PARAMS' => $URL_PARAMS, 'PAGE_PARAMS' => $PAGE_PARAMS];
    },
]);

// Применение парсера по ключу
$result = Router::apply_parser('main', ['custom_page_param' => 'value']);
```

## 2. Публичные методы

### `__construct(array $PARAMS)`

Параметры `$PARAMS`:

| Ключ | По умолчанию | Описание |
|---|---|---|
| `url_rules` | `[]` | Массив правил: `[pattern, params, priority?]` |
| `apply_once` | `false` | Применять только первое сработавшее правило |
| `strict_mode` | `false` | `true` = `^pattern$`, `false` = подстрока |
| `point` | `'/'` | Префикс для strict-режима |
| `methods` | все HTTP | Допустимые HTTP-методы |
| `rules_handler` | `fn(...)` → массив | Функция для обработки результатов |
| `key` | auto-index | Ключ в статическом реестре |

---

### `static apply_parser(string|int $key, ...$pageParams): mixed`
Применяет парсер с указанным ключом к текущему `$_SERVER['REQUEST_URI']`. Возвращает `null`, если парсер не найден.

---

### `apply(...$pageParams): mixed`
Применяет правила к текущему URL, находит сработавшие правила (по максимальному приоритету), вызывает `rules_handler`. Бросает `\Exception`, если HTTP-метод недопустим.

`rules_handler` получает три параметра:
- `$PARSER_PARAMS` — массив `params` из сработавших правил
- `$URL_PARAMS` — массив захваченных `{param}` для каждого правила
- `$PAGE_PARAMS` — аргументы, переданные в `apply()`

---

### `__get(string $name): mixed`
Доступ к параметрам экземпляра: `apply_once`, `strict_mode`, `point`, `methods`.
