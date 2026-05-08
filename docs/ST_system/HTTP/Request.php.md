# Request.php

> Для AI-агентов: этот документ описывает **всю** публичную поверхность класса `Request`.  
> Класс живёт в пространстве имён `ST_system\HTTP`, файл `HTTP/Request.php`.

---

## 1. Концепция

`Request` — **Singleton-обёртка HTTP-запроса**. Инстанции нельзя создать напрямую — только через `Request::fetch()` или статические методы.

Ключевые идеи:

1. **Lazy-инициализация.** `$_GET`, `$_POST`, `$_FILES`, заголовки и прочие данные читаются лениво при первом обращении.

2. **Параметры в URL (раутинг).** `Request::fetch()->pattern('{id}')` выделяет названные группы из URI и делает их доступными через `$request->query('id')`.

3. **Объединённый источник `data`.** `Request::data()` возвращает слитые данные `get + post + query + files`.

4. **Валидация через `Rule` схемы.** À гверенные из `Request` классы могут определить `__schema()` — который автоматически валидирует и трансформирует входящие данные сразу при создании запроса.

5. **JSON-тело.** `$_POST` автоматически объединяется с `php://input` если он содержит JSON.

---

## 2. Публичные статические методы

### `static fetch(array $query_params = []): self`

Инициализирует или переинициализирует singleton-инстанцию. На этой стадии выполняется `__schema()` и `__init()`.

| Параметр | Описание |
|----------|----------|
| `$query_params` | Дополнительные URL-параметры (инъекция, не из `$_GET`). |

---

### `static __callStatic(string $name, array $arguments): mixed`

Магический доступ к методам инстанции через статику. Автоматически вызывает `fetch()` если инстанция ещё не создана.

```php
// Аналогичные вызовы:
Request::uri();               // '/about'
Request::method();            // 'GET'
Request::get('name');         // значение из $_GET['name']
Request::post('email');       // значение из $_POST['email'] или JSON
Request::data('field');       // мерж get + post + query + files
Request::headers('Origin');   // значение HTTP-заголовка
Request::files('avatar');     // массив загруженных файлов
```

---

## 3. Доступные именованные свойства (instance-уровень)

Доступны через `$request->name()` или `Request::name()` (static).

| Свойство | Описание |
|----------|----------|
| `uri` | Путь URI (без query string), из `REQUEST_URI` |
| `host` | `$_SERVER['HTTP_HOST']` |
| `port` | Порт сервера |
| `scheme` | `'http'` или `'https'` |
| `origin` | `scheme + '://' + host` |
| `url` | `origin + uri` |
| `method` | HTTP-метод. Учитывает `$_POST['_method']` для спуфинга |
| `headers` | Ассоциативный массив HTTP-заголовок |
| `get` | `$_GET` |
| `post` | `$_POST` + JSON-тело из `php://input` |
| `cookie` | `$_COOKIE` |
| `query` | URL-параметры из шаблона (`pattern()`) |
| `files` | Нормализованные `$_FILES` |
| `data` | Объединённый массив `get + post + query + files` |

---

## 4. Расширение (subclass с схемой и init)

```php
class ApiRequest extends \ST_system\HTTP\Request {

    protected function __schema(): array {
        return [
            'name'  => 'required|string|max:100',
            'email' => 'required|email',
            'age'   => 'sometimes|int|min:18',
        ];
    }

    protected function __init(): void {
        // POST-init логика
    }
}

// Регистрация в Route:
Route::post('/api/users', 'Controller@store')
    ->request(ApiRequest::class); // Request::fetch() будет ApiRequest
```
