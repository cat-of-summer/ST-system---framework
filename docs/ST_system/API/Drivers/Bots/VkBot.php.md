# VkBot

> Документация сгенерирована AI-агентом на основе исходного кода.

## 1. Концепция

Драйвер [VK API](https://dev.vk.com/reference). Требует авторизации перед вызовом API-методов. Базовая версия API: `5.258`. Параметр `v` и `access_token` автоматически добавляются во все запросы. Проверяет соответствие `scope` для каждого метода.

```php
$vk = VkBot::create(['client_id' => 1234567, 'client_secret' => 'secret']);

// 1) Получить ссылку авторизации
$authUrl = $vk->call('authorize', [
    'redirect_uri'  => 'https://example.com/callback',
    'scope'         => ['friends'],
    'response_type' => 'code',
]);
header('Location: ' . $authUrl);

// 2) Получить access_token
$vk->call('access_token', ['code' => $_GET['code']]);

// 3) Вызов API
$users = $vk->call('users.get', ['user_ids' => [1]]);
```

## 2. Публичные методы

### `static create(array $PARAMS): static`
Параметры: `client_id` (int), `client_secret` (string).

### `call(string $method, array $params): mixed`

| Метод | Описание |
|---|---|
| `authorize` | Генерирует URL OAuth. `redirect_uri`, `scope` (массив), `display` (page/popup/mobile), `response_type` (code/token). |
| `access_token` | Получает и сохраняет `access_token`. `code` · для `code`-флоу. |
| `users.get` | Получение пользователей. `user_ids`, `fields` (массивы). |
| `users.getFollowers` | Подписчики. Требует `scope: friends`. `user_id`, `count`, `offset`, `fields`. |
| `friends.add` | Добавить в друзья. `user_id` — обязат. |
| `friends.delete` | Удалить из друзей. `user_id` — обязат. |
| `friends.getSuggestions` | Рекомендации. `filter` (mutual), `count`, `offset`, `fields`. |
| `account.ban` | Заблокировать. `owner_id` — обязат. |.php
