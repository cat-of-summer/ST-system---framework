# Mistral

## 1. Концепция

Драйвер [Mistral AI API](https://docs.mistral.ai). Наследует от `OpenAICompatibleDriver`. Поддерживает режим диалога (история хранится в кэше по алиасу). Оётвет кэшируется на 3600 с.

```php
$ai = Mistral::create(['token' => 'sk-...', 'alias' => 'support-bot']);

// Простой запрос
$reply = $ai->ask('Как ты?');

// Диалог (история в кэше)
$reply = $ai->ask('Продолжим разговор', ['conversation' => true]);

// Прямой вызов API
$response = $ai->call('completions', [
    'model'    => 'mistral-large-latest',
    'messages' => [['role' => 'user', 'content' => 'Текст']],
]);

// Управление историей
$history = $ai->getHistory();
$ai->clearHistory();
```

## 2. Публичные методы

### `static create(array $PARAMS): static`
Параметры: `token` (обязат.), `alias` (уникальное имя для кэша диалога).

### `call('completions', array $params): mixed`

| Параметр | Описание |
|---|---|
| `model` | Модель. По умолчанию — первая от `models`. |
| `messages` | Массив сообщений `[role, content]` (обязат.). |
| `temperature` | Температура (0–2). |
| `max_tokens` | Максимальное число токенов. |
| `stream` | Потоковый режим. |

### `ask(string|array $input, array $options = []): string`
Удобный метод чата. Возвращает текст ответа. `$options['conversation']` (bool) — хранить/использовать историю диалога.

### `getHistory(int $count = 0, int $start = 0): array`
История диалога. `$count=0` — все сообщения.

### `getHistorySize(string $unit = 'b'): int|float|string`
Размер истории в байтах. `$unit` передаётся в `Main::formatBytes()`, поэтому доступны все его режимы — включая авторежим и разложение по единицам.

```php
$ai->getHistorySize();          // 18432 (int)
$ai->getHistorySize('kb');      // 18.0 (float)
$ai->getHistorySize('');        // '18 KB'
$ai->getHistorySize('MB KB');   // '0 MB 18 KB'
```

### `clearHistory(int $count = 0, int $start = 0): void`
Очистка истории (полная или конкретный срез). Обновляет кэш.
