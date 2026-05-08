# Route.php

> Для AI-агентов: этот документ описывает **всю** публичную поверхность класса `Route`.  
> Класс живёт в пространстве имён `ST_system\HTTP`, файл `HTTP/Route.php`.

---

## 1. Концепция

`Route` — **флюентный реестр маршрутов** для HTTP API. Финальный класс. Все маршруты хранятся в статическом списке `$routes`.

Ключевые идеи:

1. **`Route::point($prefix)`** — обязательное начало. Устанавливает базовый URL-префикс (точка входа API).

2. **Маршруты.** Добавляются через `Route::get()`, `post()`, `put()`, `patch()`, `delete()`, `options()`, `any()`, `match()`.

3. **Группы и префиксы.** `Route::prefix('/admin')->group(function() { Route::get(...); })`.

4. **Мидлвары.** `Route::middleware(['auth', 'admin'])->get(...)`. Мидлвары сохраняются при маршруте, но не выполняются `Route` самостоятельно.

5. **`Route::routes()`** — возвращает весь реестр. Используется роутером приложения (например `API\Router`) для диспатчеризации.

**Полный пример:**
```php
Route::point('/api');

Route::prefix('/users')->group(function() {
    Route::get('', 'UserController@index');
    Route::post('', 'UserController@store');
    Route::get('{id}', 'UserController@show');

    Route::middleware('auth')->group(function() {
        Route::delete('{id}', 'UserController@destroy');
    });
});
```

---

## 2. Публичные методы

### `static point(string $point): self`

Устанавливает базовый префикс всех маршрутов. Объязательно вызывается перед определением маршрутов.

```php
Route::point('/api/v1');
// все дальнейшие маршруты будут начинаться с /api/v1/
```

---

### `static prefix(string $uri): self`

Добавляет префикс для следующей группы маршрутов. Используется внутри `group()`.

---

### `group(callable $c): void`

Исполняет `$c` в контексте текущего префикса/мидлваров. После выполнения — сбрасывает контекст из стека.

---

### `static middleware(string|array $mids): self`

Добавляет мидлвары в текущий контекст. Мидлвары наследуются вложенными группами.

---

### `static request(string $classname): self`

Привязывает конкретный подкласс `Request` для текущего контекста. Используется роутером при диспатчеризации для инициализации правильного подкласса запроса.

---

### `static __callStatic(string $name, array $arguments): void`

Добавляет маршрут. Доступные имена методов:

| Метод | HTTP-методы |
|------|------|
| `get` | `GET` |
| `post` | `POST` |
| `put` | `PUT` |
| `patch` | `PATCH` |
| `delete` | `DELETE` |
| `options` | `OPTIONS` |
| `any` | все методы |
| `match` | первый аргумент — массив методов |

Сигнатура: `Route::get(string $uri, mixed $controller, array $params = [])`

| Параметр | Описание |
|----------|----------|
| `$uri` | URI относительно текущего префикса. Поддерживает `{param}`. |
| `$controller` | Controller-функция или `'ClassName@method'` |
| `$params['strict_mode']` | `bool` — `true` (умолч) — `{param}` не совпадает со `/` |

**Бросает:** `RuntimeException` если маршрут с таким URI и методами уже зарегистрирован.

---

### `static routes(): array`

Возвращает весь реестр маршрутов. Каждый элемент — объект со следующими полями:

| Поле | Тип | Описание |
|------|-----|----------|
| `pattern` | `string` | Полный URI |
| `methods` | `string[]` | HTTP-методы |
| `controller` | `mixed` | Цонтроллер |
| `middlewares` | `array` | Мидлвары |
| `request` | `string\|null` | Класс Request |
| `strict_mode` | `bool` | Режим строгого паттерна |

```php
$routes = Route::routes();
// [
//   (object)['pattern' => '/api/users/', 'methods' => ['GET'], 'controller' => ..., ...],
// ]
```
