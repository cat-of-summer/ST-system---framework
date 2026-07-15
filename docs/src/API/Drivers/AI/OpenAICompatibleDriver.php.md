<!-- DOCGEN:START -->
# OpenAICompatibleDriver.php
<!-- DOCGEN:END -->

`abstract class OpenAICompatibleDriver extends ST_system\API\IntegrationDriver` (`ST_system\API\Drivers\AI`) — общий базовый класс для AI-драйверов, совместимых с OpenAI Chat Completions API (структура запроса `{model, messages, temperature, max_tokens, stream}` и ответа `{choices: [{message: {role, content}}], usage}`). Сейчас единственный конкретный подкласс — [`Mistral`](Mistral.php.md), поэтому вся специфика (endpoint, список моделей, регистрация метода `completions`, схема валидации, заголовок авторизации, обёртка `ask()`/история диалога) пока целиком реализована в самом `Mistral`, а `OpenAICompatibleDriver` на данный момент — пустой класс-маркер: он ничего не переопределяет и не добавляет к [`IntegrationDriver`](../../IntegrationDriver.php.md), только фиксирует общее семейство "OpenAI-совместимых чат-провайдеров" по иерархии наследования и служит точкой расширения на будущее.

## Что даёт готового

От `IntegrationDriver` подкласс наследует весь HTTP/валидационный/кеш-пайплайн: `registerMethod()`/`registerMethodsMap()`, событийную систему (`before_curl_init`, `call`, `build_url` и т.д.), инстанс-кеш через `cache()`, `call()`/`callMany()`. Сам `OpenAICompatibleDriver` в текущей версии не регистрирует ни одного метода и не подписывается ни на одно событие — он не более чем прослойка в цепочке наследования между `IntegrationDriver` и конкретным чат-провайдером.

## Что должен сделать конкретный подкласс

Пока в проекте один провайдер, следующее реализуется на уровне подкласса (см. [`Mistral`](Mistral.php.md) как образец):

- `getDefaultConfig()` — свой `endpoint`, список поддерживаемых `models`, настройки кеша;
- `__init()` — регистрация метода `completions` (`POST`, JSON-тело) со схемой параметров `model`/`messages`/`temperature`/`max_tokens`/`stream`;
- подписка на `before_curl_init` — простановка заголовка авторизации (`Authorization: Bearer <token>` у Mistral, но у другого провайдера схема может отличаться);
- публичный API поверх `completions` — метод вида `ask()`, который приводит вход (строка/сообщение/массив сообщений) к формату `messages`, опционально ведёт историю диалога и возвращает текст ответа ассистента.

## На будущее

Если в проект добавится второй OpenAI-совместимый провайдер (например, DeepSeek, Groq, локальный vLLM-эндпоинт и т.п. с идентичным форматом chat completion), имеет смысл поднять в `OpenAICompatibleDriver` то, что будет одинаковым у всех: базовую схему параметров `completions` (`messages`/`temperature`/`max_tokens`/`stream`), нормализацию `messages` (строка/одно сообщение/массив), разбор `choices[0].message.content` и сбор `usage`. Тогда конкретному подклассу останется задать только `endpoint`, список `models` и способ передачи ключа API (заголовок/схема авторизации может отличаться от провайдера к провайдеру).
