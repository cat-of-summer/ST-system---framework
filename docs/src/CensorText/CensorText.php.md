# CensorText

> Документация сгенерирована AI-агентом на основе исходного кода.

## 1. Концепция

Фильтрация нецензурных / запрещённых слов в тексте. Слова группируются в категории. Нормализация: `6`→`б`, `@`→`а`, `0`→`о` и др., чтобы обойти литеральные замены. Результаты кэшируются по md5 текста.

```php
$censor = new CensorText([
    'mat'    => ['х...', 'б...'],
    'insult' => ['дурак', 'идиот'],
]);

$result = $censor->check('Ты идиот!');
// ['mat' => false, 'insult' => true]

$censor->checkAll('Ты идиот!'); // true
$censor->countAll('текст');     // общее число
$censor->censor('ты идиот');  // 'ты ****'
```

## 2. Публичные методы

### `__construct(array $bad_words, bool $use_normalization = true)`
`$bad_words` — ассоциативный массив `['category' => ['word1', 'word2']]`.

### `check(string $text): array`
Возвращает `['category' => true|false]` — найдено ли хотя бы одно слово в категории.

### `checkAll(string $text): bool`
Найдено ли хотя бы одно запрещённое слово.

### `count(string $text): array`
Число вхождений по категориям.

### `countAll(string $text): int`
Общее число вхождений.

### `censor(string $text): string`
Заменяет найденные слова звёздочками `***`.

### `normalization_map_add(array $map): void`
Добавляет собственные правила нормализации..php
