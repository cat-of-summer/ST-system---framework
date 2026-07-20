<!-- DOCGEN:START -->
# EmitsEvents.php
<!-- DOCGEN:END -->

## Назначение

`EmitsEvents` — общее ядро событийной механики, из которого собраны оба публичных трейта: [`HasEvents`](HasEvents.php.md) (инстансный) и [`HasStaticEvents`](HasStaticEvents.php.md) (статический). Здесь живёт лишь то, что у них **одинаково**; хранилище слушателей и соглашение вызова (`on`/`fire`/`trigger`) добавляет каждый адаптер поверх.

Причина такого разделения — жёсткое ограничение PHP: **единый метод для `Class::on()` и `$obj->on()` невозможен.**

- Статический метод, вызванный как `$obj->on()`, исполняется статически и **не получает `$this`** — привязать слушателей к конкретному экземпляру нельзя.
- Один трейт не может объявить `on()` разом `public static` и `public function` (одно имя — одна «статичность»).
- Маршрут через `__call`/`__callStatic` закрыт: классы-потребители (`Lang`, `Debug`, `View`, инстансные хосты) уже держат собственные магические методы.

Поэтому соглашение вызова разнесено в два тонких адаптера, а повторяемая механика вынесена сюда — без дублирования.

## Что даёт

### `protected static function getReservedEvents(): array`

По умолчанию `[]`. Класс переопределяет, перечисляя «внутренние» события, которые нельзя эмитить снаружи через `trigger()` — только самому классу через `fire()`.

### `private static function emitTo(array $listeners, array $params)`

Единственный цикл рассылки, общий для обоих трейтов. Возвращает `false`, если слушателей нет (вызывающий откатывается на поведение по умолчанию), иначе `null` (после `foreach` без явного `return`). Строгое сравнение `=== false` по всему фреймворку отличает «не обработано» от «слушатель вернул `null`».

**By-ref.** `$params` приходит из by-ref variadic адаптера (`fire(&...$p)`). Ссылочные элементы массива переживают его копирование при передаче в `emitTo`, поэтому `call_user_func_array($listener, $params)` связывает `&$x` слушателя с переменной вызывающего — данные из события возвращаются только так, возвраты игнорируются.

### `private static function assertNotReserved(string $event): void`

Бросает `\LogicException`, если событие в `getReservedEvents()`. Зовётся из `trigger()` обоих адаптеров.

## Как собрано

```
EmitsEvents            ← механика (emitTo, assertNotReserved, getReservedEvents)
  ├─ use в HasEvents        + private array  $listeners  + инстансные on/fire/trigger
  └─ use в HasStaticEvents  + private static array $listeners + статические on/fire/trigger
```

Классы-потребители подмешивают адаптер (`use HasEvents` / `use HasStaticEvents`), про `EmitsEvents` знать не нужно.
