<!-- DOCGEN:START -->
# FaqPage.php
<!-- DOCGEN:END -->

`ST_system\Schemas\SchemaOrg\FaqPage` — схема структурированных данных [schema.org/FAQPage](https://schema.org/FAQPage): список вопросов-ответов для FAQ-страницы. Наследует `DefaultSchema` (см. `docs/src/Schemas/DefaultSchema.php.md` за общим движком полей/валидации/печати). Вложенные вопросы описываются отдельной схемой `FaqPage\Question`.

## Поля

- **`questions`** (обязательное) — массив вложенных схем `Question` (маркер `arrayOf('question')`, резолвится в `FaqPage\Question` по правилу вложенного namespace `resolveRef()`).

## Вывод

`print()` собирает JSON-LD:

```json
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [ /* toArray() каждого Question */ ]
}
```

и оборачивает его в `<script type="application/ld+json">...</script>`.

## Пример использования

```php
use ST_system\Schemas\SchemaOrg\FaqPage;

$page = FaqPage::create()->fill([
    'questions' => [
        ['question' => 'Как записаться на приём?', 'answer' => 'Через форму на сайте или по телефону.'],
        ['question' => 'Нужен ли полис ОМС?', 'answer' => 'Нет, приём платный.'],
    ],
]);

echo $page->print();
```

Каждый элемент массива `questions` автоматически коэрсится в экземпляр `FaqPage\Question` (см. его документацию) — передавать готовые объекты `Question` не обязательно, достаточно ассоциативных массивов с ключами `question`/`answer`.
