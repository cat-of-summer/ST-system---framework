# Mime.php

## 1. Концепция

Базовый класс MIME-сервиса. Все наследники получают `$this->file` (объект `File`). Сервис резолвится по MIME-типу **лениво** — при первом обращении к нему, а не в `File::make()`.

Если наследник заводит собственный кэш, он должен объявить его как `protected Cache $cache` — тогда базовый `purge()` подхватит его без переопределения.

## 2. Публичные методы

### `get(mixed $data): mixed`
Преобразует данные при чтении из кэша (базово — так же возвращает данные).

### `set(mixed $data, int &$flags = 0): mixed`
Преобразует данные при записи в кэш (базово — прозрачно).

### `toHTML(array $config = []): string`
HTML-представление. Базово — пустая строка.

### `purge(bool $storage = true): void`
Сбрасывает состояние сервиса. Базовая реализация вызывает `$this->cache->purge($storage)`, если свойство `$cache` объявлено и инициализировано; у сервисов без кэша (`JsonMime`, `TextPlainMime`, дефолтный анонимный) — no-op.

Вызывается из `File::purge()`. Наследники, хранящие состояние в памяти, переопределяют его и зовут `parent::purge($storage)`:

```php
public function purge(bool $storage = true): void {
    $this->dom = $this->xpath = null;
    parent::purge($storage);
}
```

### `static getAttrString(array $attrs): string`
Сериализует массив атрибутов в HTML-строку: `['class' => 'a', 'hidden' => true] -> 'class="a" hidden'`.