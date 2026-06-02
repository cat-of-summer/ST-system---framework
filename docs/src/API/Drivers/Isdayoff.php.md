# Isdayoff

> Документация сгенерирована AI-агентом на основе исходного кода.

## 1. Концепция

Драйвер API [isdayoff.ru](https://isdayoff.ru) — проверка рабочего/нерабочего дня в России. Ответ API разбивается на массив цифр (`0` = рабочий, `1` = выходной).

```php
$api = Isdayoff::create(['pre' => false, 'covid' => false]);

// Проверка одного дня
$result = $api->call('getdata', ['year' => 2024, 'month' => 1, 'day' => 1]); // ['1']

// Проверка диапазона
$result = $api->call('getdata', ['date1' => new \DateTime('2024-01-01'), 'date2' => '2024-01-10']);
```

## 2. Публичные методы

### `static create(array $PARAMS = []): static`

Параметры: `pre` (bool), `covid` (bool), `sd` (bool), `delimeter` (string).

### `call('getdata', array $params): array`

- `year`, `month`, `day` — целые числа
- `date1`, `date2` — диапазон дат (`\DateTimeInterface`, строка или `null`)
- `pre`, `covid`, `sd`, `delimeter` — переопределяют настройки драйвера.php
