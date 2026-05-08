# OpenAICompatibleDriver

> Документация сгенерирована AI-агентом на основе исходного кода.

## 1. Концепция

Абстрактный базовый класс для драйверов, работающих с API совместимыми с OpenAI. Наследует от `IntegrationDriver`. Сам 404 по себе пуст и служит маркером для конкретных реализаций (например, `Mistral`).

```php
// Не инстанцируется напрямую — только через наследников
class MyAI extends OpenAICompatibleDriver {
    protected string $ENDPOINT = 'https://my-ai-api.example.com/v1/chat/';
    // ...
}
```

## 2. Публичные методы

Наследует все методы `IntegrationDriver`. Собственных методов нет..php
