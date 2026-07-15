<!-- DOCGEN:START -->
# AI

## Файлы

- [Mistral.php](Mistral.php.md)
- [OpenAICompatibleDriver.php](OpenAICompatibleDriver.php.md)

<!-- DOCGEN:END -->

Драйверы для интеграции с AI-провайдерами, совместимыми с OpenAI Chat Completions API. [`OpenAICompatibleDriver`](OpenAICompatibleDriver.php.md) — общий абстрактный предок семейства (сейчас пустой класс-маркер, вся логика пока в подклассе); [`Mistral`](Mistral.php.md) — единственный на данный момент конкретный клиент (Mistral AI), с методом `ask()` для диалога и опциональным ведением персистентной истории сообщений.
