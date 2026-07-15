<!-- DOCGEN:START -->
# VkBot.php
<!-- DOCGEN:END -->

`final class VkBot extends ST_system\API\IntegrationDriver` (`ST_system\API\Drivers\Bots`) — драйвер **ВКонтакте API** (`https://api.vk.com/method`) с полным циклом **OAuth**-авторизации (`https://oauth.vk.com`): построение ссылки авторизации, обмен кода на `access_token`, проверка прав (`scope`) перед вызовом метода. Версия API зафиксирована константой `API_VERSION = '5.258'` и автоматически подставляется в каждый запрос.

## Создание инстанса

```php
use ST_system\API\Drivers\Bots\VkBot;

$vk = VkBot::create([
    'client_id'     => 123456,        // int
    'client_secret' => 'CLIENT_SECRET', // string
]);
```

## OAuth-цикл

### 1. `authorize` — построение ссылки на страницу авторизации

Это не HTTP-вызов, а локальный метод-замыкание (регистрируется как `\Closure`, минует HTTP-пайплайн `IntegrationDriver`) — возвращает готовый URL, на который нужно перенаправить пользователя.

```php
$url = $vk->call('authorize', [
    'redirect_uri'  => 'https://example.com/vk/callback',
    'scope'         => ['friends', 'account'],
    'display'       => 'page',       // page|popup|mobile, по умолчанию 'page'
    'response_type' => 'code',       // code|token, по умолчанию 'token'
]);
// редиректим пользователя на $url
```

Вызов сохраняет переданные `redirect_uri`/`scope`/`response_type` на инстансе (нужны на следующем шаге) и сбрасывает текущие `code`/`access_token`.

### 2. `access_token` — получение токена

```php
// для response_type = 'code' (после того как VK вернул ?code=... на redirect_uri)
$vk->call('access_token', ['code' => $_GET['code']]);

// для response_type = 'token' (implicit flow, токен уже пришёл от VK)
$vk->call('access_token', ['access_token' => '...', 'user_id' => 12345, 'expires_in' => 86400]);
```

Логика: сначала пытается найти уже сохранённый токен через `load_token(['user_id' => ..., 'client_id' => ...])`; если не найден — либо обменивает `code` на токен POST-запросом к `oauth_point`, либо (для `token`-флоу) берёт результат прямо из переданных параметров, — после чего валидирует его (`access_token`, `user_id`, `expires_in`) и сохраняет через `save_token()`. Полученный `access_token`/`user_id` сохраняются на инстансе и автоматически подставляются во все последующие вызовы методов API.

## Вызов методов API

После получения токена методы вызываются как обычно через `call()`. Каждый вызов проверяет два условия: наличие `access_token` (иначе исключение "необходим авторизационный токен") и достаточность прав — сравнение `meta.scope` метода с `scope`, полученным на шаге `authorize()` (при недостатке прав — исключение с перечислением недостающих scope).

Зарегистрированные методы: `users.getFollowers` (`scope: friends`; `user_id`, `count`, `offset`, `fields`, по умолчанию текущий `user_id`/`count=100`/`offset=0`), `users.get` (без scope; `user_ids`, `fields`), `account.ban` (`scope: account`; `owner_id`), `friends.delete` (`scope: friends`; `user_id`), `friends.getSuggestions` (`scope: friends`; `filter=mutual`, `count`, `offset`, `fields`), `friends.add` (`scope: friends`; `user_id`, `text`, `follow`).

```php
$followers = $vk->call('users.getFollowers', ['count' => 50]);
$vk->call('friends.add', ['user_id' => 98765, 'text' => 'Привет!']);
```
