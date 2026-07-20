<!-- DOCGEN:START -->
# HasStaticEvents.php
<!-- DOCGEN:END -->

## Назначение

`HasStaticEvents` — статический близнец [`HasEvents`](HasEvents.php.md): тот же pub/sub-механизм (`on()` / `fire()` / `trigger()` / `getReservedEvents()`), но слушатели хранятся в `private static array $listeners` — **на класс, а не на объект**.

Нужен классам-фасадам, у которых нет единого инстанса для событий:

- `Access`, `Debug` — синглтоны (`HasInstance`), но их состояние-инстанс не для событий; события логичнее держать статически.
- `Lang` — чистый статический фасад.
- `View` — приватный конструктор с **обязательными** аргументами (инстанс = один шаблон), поэтому инстанс-события `HasEvents` вообще неприменимы; это и есть главная причина существования трейта.

Раньше эти классы эмулировали статические события обёрткой `public static on() { getInstance()->_on(...); }` над `HasEvents`. Костыль убран — они используют `HasStaticEvents` напрямую.

## Отличия от `HasEvents`

| | `HasEvents` | `HasStaticEvents` |
|---|---|---|
| хранилище | `private array $listeners` (инстанс) | `private static array $listeners` (класс) |
| `on()` | `public function` | `public static function` |
| `fire()` | `protected function` | `protected static function` |
| `trigger()` | `public function` | `public static function` |
| подписка | `$obj->on(...)` | `Class::on(...)` |

Семантика идентична:

- **много слушателей** на событие, вызываются в порядке регистрации;
- данные из слушателей — **только по ссылке** (`&...$params`); возвраты `fire()` отбрасывает;
- **нет слушателей → `fire()` возвращает `false`** (идиома «поведение по умолчанию»), иначе `null`; сравнивать строго `=== false`;
- `trigger()` отказывает для событий из `getReservedEvents()` — приложение может слушать внутренние события, но не эмитить их.

Статические свойства трейта **отдельны на каждый использующий класс**, поэтому слушатели `View`, `Lang`, `Access` не пересекаются.

## Применение во фреймворке

- `Access::on('throw'|'ban'|…)`, `Debug::on('on_error'|…)`, `Lang::on('source_call')` — точки расширения фасадов.
- `View` — весь событийный шов кеша (`render_open`, `cache_open`/`cache_close`, `cache_replay`, `slot_*`, `cache_key`, `compose_collect`), на который подписываются контрибьюторы (`Assets`, `Lang`, `Storage\File`). См. [View.php](../View.php.md#вкладчики-в-кеш-через-события).
