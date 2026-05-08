# MaxBot

> Документация сгенерирована AI-агентом на основе исходного кода.

## 1. Концепция

Драйвер [Max messenger API](https://dev.max.ru/docs-api). Авторизация через заголовок `Authorization: <token>`. Использует трейт `HasHTMLRules` для нормализации HTML в формат Max. Параметры `user_id`/`chat_id` передаются через query-строку, а тело — JSON.

```php
$max = MaxBot::create('ваш-токен');

// Текст
$max->call('messages', ['user_id' => 123456789, 'text' => 'Привет!']);

// HTML автоматически нормализуется
$max->call('messages', [
    'chat_id' => 987654321,
    'text'    => '<b>Жирный</b> текст',
    'format'  => 'html',
]);
```

## 2. Публичные методы

### `static create(string $token): static`

### `call('messages', array $params): mixed`

| Параметр | Описание |
|---|---|
| `user_id` | ID пользователя (указать `user_id` или `chat_id`) |
| `chat_id` | ID чата |
| `text` | Текст сообщения до 4000 символов |
| `format` | `markdown` или `html` |
| `notify` | Уведомлять участников (дефолтно `true`) |
| `attachments` | Массив вложений (inline-кнопки и др.) |
| `link` | Ссылка на другое сообщение |
| `disable_link_preview` | Отключить превью (попадает в URL, а не в JSON) |.php
