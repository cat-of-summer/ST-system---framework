<!-- DOCGEN:START -->
# Kernel.php
<!-- DOCGEN:END -->

`final class Kernel` (`ST_system\Console\Kernel`) — ядро обнаружения, регистрации и диспатча CLI-команд ([`Command`](Command.php.md)). Использует трейт `HasConfig` для конфигурации каталога и неймспейса поиска команд по умолчанию. Класс статический — не инстанциируется (`private function __construct()`), всё состояние хранится в статических свойствах.

## Конфиг по умолчанию

```php
[
    'default' => [
        'dir'       => '~/Console/Commands',
        'namespace' => 'Console\Commands',
    ],
]
```

`dir` разрешается через `Main::preparePath()` — префикс `~` заменяется на `DOCUMENT_ROOT`/`COMPOSER_ROOT` из окружения, то есть по умолчанию команды ищутся в `<корень проекта>/Console/Commands` под неймспейсом `Console\Commands`.

## registerDir(string $dir, string $namespace): void

Рекурсивно сканирует каталог `$dir` на `*.php`-файлы (через `File::find()`). Для каждого файла восстанавливает полное имя класса как `$namespace . '\\' . <относительный путь без .php, / заменены на \\>`, и если такой класс существует, является подклассом `Command` и имеет непустой `$signature` — регистрирует его через `register()`. Файлы, не подошедшие под эти условия (класс не найден, не подкласс `Command`, либо `$signature === ''`), молча пропускаются.

## register(string $name, string $class): void

Кладёт пару `сигнатура => полное имя класса команды` в статический реестр `self::$commands`. Обычно вызывается косвенно из `registerDir()`, но доступен и для ручной регистрации одной команды без сканирования каталога:

```php
Kernel::register('user:create', \Console\Commands\UserCreateCommand::class);
```

## handleCLI(array $argv): void

Точка входа для запуска команд из CLI-скрипта:

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/vendor/autoload.php';
\ST_system\Console\Kernel::handleCLI($argv);
```

1. Проверяет, что скрипт выполняется под CLI SAPI — иначе бросает `\RuntimeException`.
2. При первом вызове в рамках процесса (статический флаг) автоматически регистрирует команды из конфигурного каталога по умолчанию через `registerDir()` — повторные вызовы `handleCLI()` не пересканируют каталог заново.
3. Имя команды берётся из `$argv[1]`. Если оно не передано или не зарегистрировано — печатается `Unknown command: ...` и список доступных команд (`implode(', ', array_keys(self::$commands))`), процесс завершается кодом `1`.
4. Остаток аргументов (`array_slice($argv, 2)`) разбирается на позиционные значения и опции:
   - `--name=value` → `$rawOptions['name'] = 'value'`;
   - `--flag` (без `=`) → `$rawOptions['flag'] = true`;
   - `-x` / `-xvalue` (короткая опция, `/^-([a-zA-Z])(.*)$/`) → `$rawOptions['x'] = true` или значение после буквы;
   - всё остальное считается позиционным аргументом.
5. Создаёт экземпляр найденного класса команды (`new $commandClass($positional, $rawOptions)`) и вызывает `handle()`.

```
$ php console.php user:create Ivan ivan@example.com --role=admin -f
```

`user:create` — имя команды (сигнатура), `Ivan`/`ivan@example.com` — позиционные аргументы, `--role=admin` и `-f` — опции; всё это передаётся в конструктор `UserCreateCommand`, чья разобранная сигнатура сопоставляет их с `name`/`email`/`role`/`force` (см. [Command.php.md](Command.php.md)).
