# Daemon.php

> Для AI-агентов: этот документ описывает **всю** публичную поверхность класса `Daemon`.  
> Класс живёт в пространстве имён `ST_system`, файл `Daemon.php`.

---

## 1. Концепция

`Daemon` — **базовый класс для долгоживущих фоновых процессов** с удобным управлением жизненным циклом. Использует `HasAttributes`, `HasConfig`, `HasEvents`.

Ключевые идеи:

1. **Сигналы ОС.** Автоматически перехватывает `SIGTERM`/`SIGINT` (остановка) и `SIGHUP` (reload).

2. **Цикл итерации.** Метод `run()` вызывается в бесконечном цикле с паузой `config('interval')` секунд между итерациями.

3. **Повторные попытки.** Если `run()` бросает исключение — вызывается событие `error`. После `config('retries')` ошибок подряд — демон останавливается.

4. **Контрольные точки (checkpoint / goal).** Позволяют структурировать процесс как последовательность этапов, каждый из которых срабатывает по условию.

5. **Всегда вызывается через `__callStatic`.** Напрямую `new MyDaemon()` недоступен (конструктор `final private`). Создание идёт через `MyDaemon::init()->run()`.

**Дефолтная конфигурация:**

| Ключ | Умолчание | Описание |
|-----|---------|----------|
| `interval` | `1` | Пауза в секундах между итерациями. `0` — без паузы. |
| `retries` | `3` | Макс. кол-во ошибок подряд до остановки. |

Зарезервированные события (triggers-запрещены снаружи): `'commit'`, `'reload'`.

---

## 2. Публичные методы через `__callStatic`

### `static init(callable $fn = null): self`

Создаёт экземпляр демона. Если передан callback — добавляет его к init-цепочке после виртуального `init()`. callback получает ссылку на `$this`.

```php
MyDaemon::init(function(MyDaemon $d) {
    // дополнительная инициализация
})->run();
```

---

## 3. Публичные методы через `__call`

### `run(callable $fn = null): self`

Запускает главный цикл. Если передан `$fn` — заменяет виртуальный `run()`. `$fn` получает `$this`.

Сначала вызывает `init()`, затем стартует цикл `while ($running)`.

```php
MyDaemon::run(function(MyDaemon $d) {
    // логика одной итерации
    $d->doWork();
});
```

---

### `on{Event}(callable $fn): self`

Container для событий. Работает через `__call`: каждый метод вида `on{Event}` регистрирует слушателя через трейт `HasEvents`.

Встроенные события:

| Событие | Аргументы | Описание |
|--------|------------|----------|
| `error` | `(Throwable $e, int $retries)` | Ошибка в цикле |
| `commit` | — | Процесс завершается (через shutdown function). **Не вызывается**, если завершение вызвано фатальной ошибкой — исчерпаны `retries` или исключение брошено из `init()` |
| `reload` | — | Получен сигнал `SIGHUP` |

```php
MyDaemon::init()
    ->onError(function(\Throwable $e, int $retries) {
        error_log("Daemon error (retry #{$retries}): " . $e->getMessage());
    })
    ->onCommit(function() {
        // ссохраняем статус перед выходом
    })
    ->onReload(function() {
        // перечитываем конфиг
    })
    ->run(function($d) {
        $d->processQueue();
    });
```

---

## 4. Защищённые методы (для переопределения в подклассе)

### `protected void init()` / `protected void run()`

Оверрайдяемые методы в подклассе. `init()` — одноразовая инициализация, `run()` — логика одной итерации. По умолчанию пустые.

```php
class QueueWorker extends \ST_system\Daemon {
    protected function init(): void {
        // запускается один раз
        $this->connection = new QueueConnection();
    }

    protected function run(): void {
        // запускается каждую итерацию
        $job = $this->connection->dequeue();
        if ($job) $job->execute();
    }
}

QueueWorker::run(); // запуск цикла
```

---

### `final protected checkpoint(string $name, callable $cond, bool $once = true, int $interval = 0): void`

Регистрирует контрольную точку. Условие срабатывания — `$cond()` возвращает `true`.

| Параметр | Описание |
|----------|----------|
| `$name` | Уникальное имя точки |
| `$cond` | callable — условие достижения |
| `$once` | `true` — срабатывает один раз |
| `$interval` | Мин. интервал проверки в секундах |

Точки образуют **цепочку**: каждая новая точка запоминает номер предыдущей и не сработает, пока предыдущая не достигнута.

---

### `final protected goal(string $name, mixed &...$params): void`

Проверяет условие точки и вызывает событие через `fire()`. Вызывается в `run()` для проверки каждой точки.

Аргументы `$params` передаются в `$cond()` и в событие по ссылке.

```php
protected function run(): void {
    $count = $this->queue->count();
    $this->goal('queue_empty', $count); // $count передаётся в $cond
}
```
