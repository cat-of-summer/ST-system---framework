<!-- DOCGEN:START -->
# Question.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\FaqPage\Question` — вложенная схема [schema.org/Question](https://schema.org/Question), используемая исключительно внутри `FaqPage` (поле `questions`). Наследует `DefaultSchema`. Сама по себе не печатается (`print()`) — только встраивается через `toArray()` в родительскую схему, поэтому объявляет `getToArray()`, а не `getPrint()`.

## Поля

- **`question`** (обязательное) — текст вопроса; строка, `strip_tags` + `html_encode` (HTML вырезается и экранируется).
- **`answer`** (обязательное) — текст ответа; та же обработка.

## Вывод (`toArray()`)

```json
{
  "@type": "Question",
  "name": "<question>",
  "acceptedAnswer": { "@type": "Answer", "text": "<answer>" }
}
```

## Пример

```php
use ST_system\Schemas\SchemaOrg\FaqPage\Question;

$q = Question::create()->fill([
    'question' => 'Сколько длится приём?',
    'answer'   => 'Обычно 30–40 минут.',
]);

$q->toArray(); // ['@type' => 'Question', 'name' => '...', 'acceptedAnswer' => [...]]
```

На практике объекты `Question` создаются автоматически внутри `FaqPage::fill(['questions' => [...]])` — вручную конструировать эту схему нужно редко.
