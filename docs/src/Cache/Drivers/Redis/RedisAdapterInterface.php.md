# RedisAdapterInterface


## 1. Концепция

`RedisAdapterInterface` — контракт для адаптеров Redis, на которые опирается `RedisCacheDriver`. Интерфейс определяет минимальный набор хэш-операций (хэш-поля `hSet`/`hGet`/`hExists`) и операций по ключам (`del`, `scan`).

Реализации:
- `PhpRedisAdapter` — через `\Redis` (расширение `phpredis`) или `\Relay\Relay`
- `PredisAdapter` — через `predis/predis`

## 2. Публичные методы

| Метод | Описание |
|---|---|
| `static isAvailable(): bool` | Проверка наличия PHP-расширения или библиотеки |
| `static connect(array $cfg): static` | Создание соединения по конфигу |
| `hSet(string $key, string $field, string $value): void` | Запись поля в хэш |
| `hGet(string $key, string $field): string\|false` | Чтение поля из хэша |
| `hExists(string $key, string $field): bool` | Проверка наличия поля |
| `del(string\|array $keys): void` | Удаление одного или нескольких ключей |
| `scan(&$cursor, string $pattern, int $count): array\|false` | Поиск ключей по паттерну (без блокировки) |.php
