# Kernel

> Документация сгенерирована AI-агентом на основе исходного кода.

## 1. Концепция

Консольный диспетчер. При первом вызове `handle()` автоматически сканирует директорию `~/Console/Commands` и регистрирует найденные команды.

```php
// Через PHP CLI: php artisan report:generate monthly --format=pdf
Kernel::handle($argv);

// Регистрация дополнительной директории
Kernel::registerDir('~/plugins/Commands', 'Plugins\Commands');

// Регистрация конкретного класса
Kernel::register('my:command', MyCommand::class);
```

## 2. Публичные методы

### `static handle(array $argv): void`
Обрабатывает `$argv`. Первый вызов запускает авто-сканирование команд из `default.dir`. Разбирает параметры `--opt=val`, `-o val` и позиционные аргументы.

### `static register(string $name, string $class): void`
Регистрация кCасса команды по имени.

### `static registerDir(string $dir, string $namespace): void`
Рекурсивно сканирует PHP-файлы от директории, регистрирует классы — наследники `Command` с непустым `$signature`..php
