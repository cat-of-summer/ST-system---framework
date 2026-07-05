# HasEvents.php

## 1. Концепция

`HasEvents` — **трейт паттерна Observer** (наблюдатель / event emitter) для PHP-объектов.

Ключевые идеи:

1. **Инстанс-уровень событий.** Слушатели привязываются к конкретному объекту, а не к классу глобально. Это позволяет независимо подписываться на события разных экземпляров.

2. **Зарезервированные события.** Класс может определить список событий через `getReservedEvents()`, которые **нельзя вызвать снаружи** через `trigger()`. Зарезервированные события вызываются только внутри класса через `fire()`.

3. **Отличие `trigger()` и `fire()`.**
   - `trigger()` — публичный API для внешнего кода. Бросает `LogicException` при попытке вызвать зарезервированное событие.
   - `fire()` — приватный метод для внутреннего использования в теле класса. Может вызывать любые события, включая зарезервированные.

4. **Передача аргументов по ссылке.** Слушатели получают аргументы **по ссылке** (`&...$params`), что позволяет им мутировать передаваемые данные.

**Когда использовать:**
- В классах с жизненным циклом (запрос, демон, драйвер интеграции), где внешний код должен реагировать на внутренние события.
- Когда нужен loosley-coupled callback-механизм без жёстких зависимостей.

```php
class DataProcessor {
    use \ST_system\Traits\HasEvents;

    protected static function getReservedEvents(): array {
        return ['before_process']; // только сам класс может вызвать это событие
    }

    public function process(array &$data): void {
        $this->fire('before_process', $data); // внутренний вызов
        // ... основная логика ...
        $this->trigger('after_process', $data); // публичное событие
    }
}

$p = new DataProcessor();
$p->on('after_process', function(array &$data) {
    $data['processed'] = true;
});
// $p->trigger('before_process', ...) → бросит LogicException
$p->process($myData);
```

---

## 2. Публичные методы

### `final on(string $event, callable $listener): void`

Подписывается на событие. Один и тот же `$event` может иметь несколько слушателей — они будут вызваны в порядке регистрации.

| Параметр | Тип | Описание |
|----------|-----|----------|
| `$event` | `string` | Имя события |
| `$listener` | `callable` | Функция-обработчик. Получает аргументы из `trigger()` / `fire()` по ссылке. |

```php
$obj->on('change', function(mixed &$newValue, mixed &$oldValue): void {
    echo "Changed from {$oldValue} to {$newValue}";
});
```

---

### `final trigger(string $event, mixed &...$params): void`

Вызывает событие для всех зарегистрированных слушателей. **Не может** вызывать зарезервированные события — бросает `LogicException`.

| Параметр | Тип | Описание |
|----------|-----|----------|
| `$event` | `string` | Имя события |
| `...$params` | `mixed` | Аргументы передаваемые слушателям по ссылке |

**Бросает:** `\LogicException` — если `$event` находится в списке `getReservedEvents()`.

```php
$value = 42;
$obj->trigger('update', $value);
// слушатели могут изменить $value
```

---

## 3. Защищённые методы (для переопределения)

### `protected static getReservedEvents(): array`

Возвращает список зарезервированных событий. Эти события **не могут** быть вызваны через публичный метод `trigger()` — только через приватный `fire()` внутри класса.

По умолчанию возвращает пустой массив `[]`.

```php
protected static function getReservedEvents(): array {
    return ['init', 'destroy', 'error'];
}
```
