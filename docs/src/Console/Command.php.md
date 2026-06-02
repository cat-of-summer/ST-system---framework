# Command

> Документация сгенерирована AI-агентом на основе исходного кода.

## 1. Концепция

Абстрактный базовый класс для консольных команд. Строка `$signature` декларирует имя, аргументы и опции команды. `Kernel` автоматически обнаруживает классы.

```php
class GenerateReport extends Command
{
    // Синтаксис сигнатуры:
    // cmd:name {arg} {arg2?} {arg3=def} {--flag} {--opt=} {--opt=def} {--a|alias=def}
    public static string $signature = 'report:generate {type} {year=2024} {--format=pdf} {--v|verbose}';

    public function handle(): void
    {
        $type    = $this->argument('type');    // обязательный аргумент
        $year    = $this->argument('year');    // опц. с дефолтом
        $format  = $this->option('format');   // --format=...
        $verbose = $this->option('verbose');  // --verbose | -v => true

        $this->line("[{$year}] Генерация отчёта '{$type}' в формате {$format}");
    }
}
```

## 2. Публичные методы

### `abstract public function handle(): void`
Точка входа. Реализуется наследником.

### `protected option(string $key = '', mixed $default = null): mixed`
Значение опции. Без `$key` — весь массив опций.

### `protected argument(string $key = '', mixed $default = null): mixed`
Значение аргумента. Без `$key` — весь массив.

### `protected line(string $text): void`
Вывод строки в консоль.

---

### Формат сигнатуры

| Токен | Тип |
|---|---|
| `{arg}` | Обязательный аргумент |
| `{arg?}` | Необязательный аргумент |
| `{arg=default}` | Аргумент с значением по умолчанию |
| `{--flag}` | Флаг (bool) |
| `{--opt=}` | Опция с значением |
| `{--opt=default}` | Опция с дефолтом |
| `{--a\|opt=}` | Опция с алиасом `-a` |.php
