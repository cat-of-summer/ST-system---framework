<!-- DOCGEN:START -->
# Route.php
<!-- DOCGEN:END -->

`namespace ST_system\HTTP`

## Назначение

`Route` — регистрация HTTP-маршрутов приложения (URI-паттерны с плейсхолдерами вида `/users/{id}`, HTTP-методы, контроллеры, middleware-стек, класс `Request`) и диспетчеризация текущего запроса на подходящий маршрут (`handleRequest()`). Класс `final`, использует статическое состояние (`self::$routes`, `self::$stack`, `self::$API_POINT`), общее для всего приложения.

Регистрация маршрутов построена на стеке "билдеров" (`self::$stack` — массив внутренних инстансов `Route`), который позволяет вложенно группировать маршруты по префиксу, middleware и классу запроса. Диспетчеризация (`handleRequest()`) использует `HTTP\Request` (для получения URI и создания объекта запроса), `HTTP\Response` (для отправки ошибок и оборачивания результата контроллера) и `Access::throw()` (для 404, если маршрут не найден).

## Магический API регистрации маршрутов

Конструктор приватный — маршруты регистрируются только через статические/цепочечные вызовы:

- **`public static function __callStatic($name, $arguments): void`** — реализует методы-глаголы HTTP:
  - `Route::get($uri, $controller, array $params = [])`, а также `post`, `put`, `patch`, `delete`, `options` — регистрируют маршрут на один HTTP-метод (`GET`, `POST`, ...).
  - `Route::any($uri, $controller, array $params = [])` — регистрирует маршрут сразу на `GET, POST, PUT, PATCH, DELETE, OPTIONS`.
  - `Route::match(array $methods, $uri, $controller, array $params = [])` — регистрирует маршрут на явно перечисленный список методов.
  - Любое другое имя метода бросает `\Error("Call to undefined method ST_system\HTTP\Route::{$name}()")`.
  - Внутри все ветки сводятся к вызову приватного `add_route($methods, $uri, $controller, $params)`, который ничего не возвращает (`void`) — то есть **эти вызовы не поддерживают дальнейшую цепочку** (`Route::get(...)->middleware(...)` невозможен; middleware/request настраиваются заранее, на уровне группы).
- **`public function __call($name, $arguments)`** — форвардит на `self::__callStatic($name, $arguments)`, то есть те же `get`/`post`/... можно вызывать и на инстансе, полученном из `prefix()`/`middleware()`/`request()` (`Route::prefix('users')->get(...)`).

`add_route(array $methods, string $uri, $controller, array $PARAMS = []): void` (приватный) — берёт текущий билдер (`self::current()`), склеивает `$base->prefix` с `$uri` в полный путь. Опция `$PARAMS['strict_mode']` (по умолчанию `true`) переключает нормализацию пути:
- `strict_mode = true` — путь оборачивается слэшами с обеих сторон (`/prefix/uri/`);
- `strict_mode = false` — без обрамляющих слэшей (`prefix/uri`).

Перед добавлением проверяет дубликаты: если для того же итогового пути уже зарегистрирован маршрут с пересекающимся набором методов — бросает `\RuntimeException("Duplicate route: {$full}")`. Сохраняет маршрут как объект со свойствами `pattern`, `methods`, `controller`, `middlewares` (из текущего билдера), `request` (класс запроса из текущего билдера) и `strict_mode`.

## Построение групп: `point()`, `prefix()`, `group()`, `middleware()`, `request()`

- **`public static function point(string $point): self`** — задаёт корневую точку API (`self::$API_POINT`, нормализуется в `/point/`), **полностью сбрасывает** стек билдеров (`self::$stack = [$new]`) и возвращает новый корневой билдер. Обычно вызывается один раз в начале файла маршрутов.
- **`private static function current(): self`** — вершина стека билдеров; если стек пуст — создаёт и кладёт в него билдер по умолчанию (использует `self::$API_POINT`, если он уже установлен, иначе бросает `\RuntimeException("Configuration error: API_POINT is not set")`).
- **`public static function prefix(string $uri): self`** — берёт текущий билдер (`current()`) как родителя, создаёт новый билдер с путём `/{parent->prefix}/{uri}/` и **тем же** списком middleware, что и у родителя, кладёт его на вершину стека (`self::$stack[] = $new`) и возвращает.
- **`public function group(callable $c): void`** — вызывает переданный колбэк `$c()` (внутри него обычно регистрируются маршруты или вложенные `prefix()`/`middleware()`), затем снимает верхний билдер со стека (`array_pop`), возвращая контекст к родительскому уровню.
- **`public static function middleware($mids): self`** — добавляет один middleware или массив middleware к **текущему** билдеру (`current()->middlewares`), не создавая новый уровень стека. `$mids` может быть строкой (имя класса), callable или массивом того и другого.
- **`public static function request(string $classname): self`** — устанавливает класс `Request` (или его наследника), который будет создан для всех маршрутов, зарегистрированных в текущем билдере, пока он на вершине стека.

Важно: `middleware()` и `request()` модифицируют **текущий** билдер на месте и действуют на все маршруты, добавленные после них и до `group()`/выхода из этого уровня — а не только на следующий отдельный маршрут.

- **`public static function routes(): array`** — возвращает все зарегистрированные маршруты (массив объектов `pattern/methods/controller/middlewares/request/strict_mode`).

## `handleRequest(?callable $process = null): void` — диспетчеризация запроса

Главная точка входа, вызывается один раз за запрос (обычно в конце файла маршрутов, после того как все `Route::get/post/...` уже описаны).

1. Открывает буфер вывода (`ob_start()`), при наличии вызывает `$process()` (например, для отложенной регистрации маршрутов внутри колбэка) и в `finally` схлопывает все вложенные уровни буфера до одного (`while (ob_get_level() > 1) ob_end_clean()`), сохраняя внешний буфер для последующих `ob_clean()`.
2. Ищет первый подходящий маршрут среди `self::routes()`: для каждого строит регулярное выражение из `pattern`. Плейсхолдеры вынимаются одним проходом (`preg_replace_callback` по грамматике `#\{(\.\.\.)?(\w+)(?::([^{}]+))?\}#`) и заменяются на preg_quote-безопасные плейсхолдеры-«слоты»; затем в `strict_mode = true` весь остаток (литеральные части пути) экранируется `preg_quote()`, а слоты возвращаются на место уже готовыми именованными группами. Такой порядок позволяет одному коду обслуживать оба режима **и** сохранять произвольные inline-regexp нетронутыми. Виды плейсхолдеров:
   - `{name}` → `(?P<name>[^/]+)` — один сегмент пути (без слешей, как раньше);
   - `{...name}` → `(?P<name>.+)` — **catch-all**, захватывает остаток пути включая слеши;
   - `{name:regex}` → `(?P<name>regex)` — произвольное inline-ограничение (аналог `->where()` в Laravel); приоритетнее двух предыдущих форм.
   Итоговый regex `#^.../?$#` сверяется с `Request::uri()`. При совпадении собирает `query_params` из именованных групп и прекращает поиск (первый подходящий маршрут побеждает, `break`). Inline-regexp корректно работает в обоих режимах; `strict_mode` (по умолчанию `true`) влияет только на экранирование **литеральных** частей пути.
3. Если маршрут не найден — `Access::throw(404)`.
4. Определяет класс запроса через рефлексию первого параметра контроллера (`ReflectionMethod`/`ReflectionFunction`): если у контроллера объявлен один типизированный параметр с именованным классовым типом, являющимся наследником `Request`, — используется именно он; при любой неудаче (нет параметров, union/intersection-тип, встроенный тип, класс не наследник `Request`) — тихо откатывается на класс, заданный `Route::request()` для маршрута, либо на базовый `Request::class`.
5. Создаёт объект запроса: `$request = $request_class::fetch($query_params)`.
6. Прогоняет middleware маршрута по порядку: каждый элемент — либо вызываемое значение/строка класса напрямую, либо массив `[middleware, ...доп.аргументы]` (первый элемент снимается как сам middleware, остаток — дополнительные аргументы). Строковое имя класса вызывается как `[$class, 'handle']`. Вызов — `call_user_func_array($target, array_merge([$request], $args))`.
7. Если на шагах 4–6 возникло исключение — буфер очищается (`ob_clean()`) и немедленно отправляется ошибка через `Response::json([...])->status(...)->send()` (этот вызов сам завершает скрипт через `exit` внутри `send()`). Текст сообщения: полный (сообщение + файл + строка + трейс, по-русски) при `Config::env('DEBUG_MODE') == true`, иначе только `$th->getMessage()`. Код ответа — код исключения, а если он равен `0`/falsy — `403`.
8. Вызывает контроллер маршрута: `[new $controller[0], $controller[1]]` для массива `[класс, метод]` (без аргументов конструктора) либо сам колбэк, передавая единственным аргументом `$request`. Если результат — не экземпляр `Response`, он оборачивается в `Response::json($response)`.
9. Если контроллер бросил исключение — формируется аналогичный отладочный/сокращённый JSON-ответ, но со статусом по умолчанию `500` (вместо `403`) — и **не** отправляется сразу, а присваивается `$response`.
10. `finally`: `ob_clean()` и `$response->send()` — фактическая отправка результата контроллера (или ошибки из шага 9) клиенту.

## Плейсхолдеры и регулярные выражения в путях

Три формы плейсхолдеров покрывают разные потребности — от одного сегмента до захвата всего хвоста пути и произвольных ограничений:

```php
Route::point('/api/v1/');

// 1) {name} — ровно один сегмент, (?P<name>[^/]+)
Route::get('/users/{id}', [UserController::class, 'show']);
//   /api/v1/users/42          -> id = "42"
//   /api/v1/users/42/posts    -> НЕ совпадает (слеши не захватываются)

// 2) {name:regex} — произвольное inline-ограничение, (?P<name>regex)
Route::get('/users/{id:[0-9]+}',        [UserController::class, 'show']);
//   /api/v1/users/42          -> id = "42"
//   /api/v1/users/abc         -> НЕ совпадает (только цифры)

Route::get('/posts/{slug:[a-z0-9-]+}',  [PostController::class, 'show']);
//   /api/v1/posts/hello-world -> slug = "hello-world"

Route::get('/reports/{y:[0-9]+}-{m:[0-9]+}', [ReportController::class, 'month']);
//   /api/v1/reports/2026-07   -> y = "2026", m = "07"   (несколько плейсхолдеров в сегменте)

// 3) {...name} — catch-all, захватывает остаток пути ВКЛЮЧАЯ слеши, (?P<name>.+)
Route::get('/commands/{...command}',    [CommandController::class, 'run']);
//   /api/v1/commands/Foo/Bar/Baz -> command = "Foo/Bar/Baz"  (передаём namespace-подобное значение)

Route::get('/files/{...path}',          [FileController::class, 'serve']);
//   /api/v1/files/img/2026/pic.png -> path = "img/2026/pic.png"

// Эквивалент catch-all через inline-regexp (то же самое, но с явным regex):
Route::get('/commands/{command:.*}',    [CommandController::class, 'run']);
```

> Примечания:
> - Хвостовой `/?$` в итоговом regex делает завершающий слеш необязательным. Для `{...name}` жадный `.+` «доедает» лишний слеш: URI `/api/v1/commands/Foo/Bar/` даст `command = "Foo/Bar/"` (со слешем на конце). Если он не нужен — используйте `{command:.*}` без хвостового слеша в самом значении или обрежьте его в контроллере/`Request`.
> - Значения именованных групп попадают в `query_params` и доступны в объекте запроса (`Request::query('id')` и т.п. — см. [Request.php.md](Request.php.md)).
> - `strict_mode = false` дополнительно оставляет литеральные части пути «сырыми» — в них можно вписать собственный regex прямо в строку маршрута.
> - **Ограничение**: inline-regexp в `{name:regex}` не может содержать `{` или `}` (грамматика плейсхолдера — `[^{}]+`). Поэтому квантификаторы-скобки вроде `\d{4}` / `[0-9]{2}` недоступны — используйте эквиваленты без фигурных скобок: `[0-9]+`, `\d\d\d\d`, классы символов и `+`/`*`/`?`.

## Пример использования

```php
Route::point('/api/v1/');

Route::prefix('users')
    ->middleware([AuthMiddleware::class])
    ->request(UserRequest::class)
    ->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::get('/{id}', [UserController::class, 'show']);
        Route::post('/', [UserController::class, 'store']);
    });

Route::get('/ping', function () {
    return ['pong' => true]; // будет обёрнуто в Response::json(...)
});

Route::handleRequest();
```
