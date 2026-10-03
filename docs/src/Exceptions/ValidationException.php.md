<!-- DOCGEN:START -->
# ValidationException.php
<!-- DOCGEN:END -->

`namespace ST_system\Exceptions`

## Назначение

Исключение провала валидации. Его бросает `Rule::throwable()` (см. [Rule.php.md](../Rule.php.md)), а значит и `Request` с `$throwable = true` — и через `->throwable()->validate()`, и через схему `__schema()` (см. [HTTP/Request.php.md](../HTTP/Request.php.md)). Наследник `\Exception`, поэтому существующие `catch (\Exception $e)` продолжают его ловить.

- **Код** — `422`. `Route::handleRequest()` берёт статус из кода исключения, поэтому ошибка ввода отличается от «нет прав» (`403`).
- **`getMessage()`** — все ошибки через `PHP_EOL` в исходном виде `поле.текст`, как раньше.
- **`getErrors(): array`** — `поле => [сообщения]`. Поле — первый сегмент до точки: `email.This field is required` → `['email' => ['This field is required']]`. У вложенных схем ключ — поле верхнего уровня, остаток пути остаётся в сообщении (`items.0.name.Must be a string` → `['items' => ['0.name.Must be a string']]`). Ошибка без точки (например, `Expected array or object` от самого `Rule::object()`) кладётся под ключ `''`.

## Ответ роутера

```json
{
    "message": "email.This field is required",
    "errors": { "email": ["This field is required"] }
}
```

Статус `422`. При `DEBUG_MODE` `message` дополняется файлом, строкой и трейсом, `errors` не меняется. Подробнее — [HTTP/Route.php.md](../HTTP/Route.php.md).

## Собственное использование

Бросать можно и вручную, когда проверка не укладывается в схему:

```php
throw new \ST_system\Exceptions\ValidationException(['email.Этот адрес уже занят']);
```
