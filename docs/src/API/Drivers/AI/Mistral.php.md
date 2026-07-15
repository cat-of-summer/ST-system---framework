<!-- DOCGEN:START -->
# Mistral.php
<!-- DOCGEN:END -->

`final class Mistral extends OpenAICompatibleDriver` (`ST_system\API\Drivers\AI`) — клиент **Mistral AI Chat Completions API** (`https://api.mistral.ai/v1/chat/`). Единственный на данный момент конкретный AI-драйвер, реализующий [`OpenAICompatibleDriver`](OpenAICompatibleDriver.php.md); построен поверх общего HTTP/кеш/валидационного пайплайна [`IntegrationDriver`](../../IntegrationDriver.php.md) — регистрирует один HTTP-метод `completions` и оборачивает его удобным API для диалога с ведением истории.

## Создание инстанса

```php
use ST_system\API\Drivers\AI\Mistral;

$mistral = Mistral::create([
    'token' => 'MISTRAL_API_KEY', // required|string
    'alias' => 'support-bot',     // nullable|string — включает персистентную историю диалога
]);
```

- `token` — обязательный, подставляется в заголовок `Authorization: Bearer <token>` перед каждым запросом.
- `alias` — если указан, инстанс регистрируется в статическом реестре `Mistral::$INSTANCES` под этим именем (повторное использование того же алиаса на другом инстансе бросает исключение), а история диалога (`$this->conversation`) при создании подгружается из инстанс-кеша (`cache()->make(static::class)->get($alias)`) и далее автоматически сохраняется туда при каждом изменении — так диалог переживает между запросами/процессами. Без `alias` история хранится только в памяти текущего инстанса.

## Конфиг по умолчанию

`endpoint` = `https://api.mistral.ai/v1/chat/`, `cache.use = true`, и список допустимых `models` (`mistral-small-latest`, `mistral-medium-latest`, `mistral-large-latest`, `open-mistral-7b`, `open-mixtral-8x7b`, `codestral-latest`, `pixtral-12b`, `pixtral-large-latest`, `ministral-3b-latest`, `ministral-8b-latest`).

## Зарегистрированный метод: `completions`

`POST` c JSON-телом, `cache_ttl` = 3600 секунд (одинаковые `messages`/`model`/... в течение часа отдают закешированный ответ). Параметры: `model` (по умолчанию — первая модель из списка, обязана входить в `models`), `messages` (обязателен, непустой массив), `temperature` (0..2), `max_tokens`, `stream`. Обычно напрямую не вызывается — используйте `ask()`.

## Публичные методы

### `ask($input, array $options = []): string`

Основной способ общения с моделью.

```php
// одна реплика
$answer = $mistral->ask('Привет! Кто ты?');

// с параметрами
$answer = $mistral->ask('Объясни рекурсию', ['model' => 'mistral-large-latest', 'temperature' => 0.3]);

// с ведением истории (нужен alias при создании инстанса, чтобы диалог сохранялся между запросами)
$answer = $mistral->ask('Как меня зовут?', ['conversation' => true]);
```

`$input` может быть:
- строкой — оборачивается в `[['role' => 'user', 'content' => $input]]`;
- одним сообщением `['role' => ..., 'content' => ...]` — оборачивается в массив из одного элемента;
- массивом сообщений `{role: assistant|system|user, content: string, prefix?: bool}`.

Если `$options['conversation'] === true`, входные сообщения добавляются в `$this->conversation`, запрос уходит с полной историей вместо только текущего ввода, а ответ ассистента дописывается обратно в историю (и сохраняется в кеш, если задан `alias`). Возвращает `content` первого варианта ответа (`choices[0].message.content`) или `''`, если он отсутствует. Данные о расходе токенов (`usage`, `id`, `created`) из ответа накапливаются во внутреннем `$this->usage`.

### `getHistory(int $count = 0, int $start = 0): array`

Возвращает срез истории диалога — `array_slice($this->conversation, $start, $count ?: null)`. С параметрами по умолчанию отдаёт всю историю.

```php
$mistral->getHistory();       // весь диалог
$mistral->getHistory(5);      // первые 5 сообщений
$mistral->getHistory(5, 2);   // 5 сообщений начиная со 2-го
```

### `getHistorySize(string $unit = 'b')`

Возвращает объём истории диалога (JSON-сериализованный, в байтах) в человекочитаемых единицах через `Main::formatBytes()`, например `$mistral->getHistorySize('kb')`.

### `clearHistory(int $count = 0, int $start = 0): void`

Без аргументов очищает историю целиком; с `$count > 0` удаляет `$count` сообщений начиная с `$start` (`array_splice`). Если у инстанса задан `alias`, изменение сразу же персистируется в кеш.
