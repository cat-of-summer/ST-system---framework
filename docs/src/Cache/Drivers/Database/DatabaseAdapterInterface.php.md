# DatabaseAdapterInterface

> Документация сгенерирована AI-агентом на основе исходного кода.

## 1. Концепция

`DatabaseAdapterInterface` — контракт для адаптеров базы данных, на которые опирается `DatabaseCacheDriver`. Определяет CRUD-операции над записями `(bucket, field, value)` и поиск через `scan`.

Реализации:
- `MysqlAdapter` — MySQL / MariaDB через PDO
- `PostgresAdapter` — PostgreSQL через PDO

## 2. Публичные методы

| Метод | Описание |
|---|---|
| `static isAvailable(): bool` | Проверка наличия PDO и нужного драйвера |
| `static connect(array $cfg): static` | Установка соединения + миграция таблицы |
| `write(string $bucket, string $field, string $value): void` | Вставка или обновление записи (upsert) |
| `read(string $bucket, string $field): string\|false` | Чтение значения |
| `exists(string $bucket, string $field): bool` | Проверка наличия записи |
| `delete(string\|array $buckets): void` | Удаление одного или нескольких `bucket`-записей |
| `scan(&$cursor, string $pattern, int $count): array\|false` | Поиск `bucket` по глоб-паттерну (пагинация через `$cursor`/OFFSET) |.php
